<?php
<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('LH_Ttx_Logger')) {
    // Fallback no-op logger if file included directly.
    final class LH_Ttx_Logger { public static function info($m,$c=[]){ } public static function error($m,$c=[]){ } }
}


/* ========================================================================== */
/* Customers Service                                                           */
/* ========================================================================== */

final class LH_Ttx_Customers_Service {

    /**
     * Create a new customer in Tripletex from WP user profile and link it.
     *
     * @param int $user_id
     * @return int|\WP_Error New Tripletex customer ID
     */
    public function create_and_link(int $user_id) {
        $payload = $this->map_user_to_tripletex_payload($user_id, ['for_create' => true]);

        if (empty($payload['name'])) {
            return new WP_Error('payload_invalid', __('Kundenavn mangler.', 'lh-ttx'));
        }

        $created_id = ttx_customers_create($payload);
        if (is_wp_error($created_id)) return $created_id;

        $this->link_user_to_tripletex($user_id, (int) $created_id);

        LH_Ttx_Logger::info('Created Tripletex customer and linked user', [
            'user_id' => $user_id,
            'ttx_id'  => (int) $created_id,
        ]);

        return (int) $created_id;
    }

    /**
     * Sync user changes to Tripletex.
     * Called from: on_profile_update, woocommerce_customer_save_address
     *
     * @param int $user_id
     * @return true|\WP_Error
     */
    public function sync_user(int $user_id) {
        $ttx_id = lh_ttx_get_linked_tripletex_id($user_id);

        if (!$ttx_id) {
            // If no link, then we do nothing
            return new WP_Error('ttx_id_not_set', __('Kunde har ikke satt tripletex id.', 'lh-ttx'));
        }

        // get current tripletex customer data
        $remote = ttx_customers_get($ttx_id);
        if (is_wp_error($remote)) return $remote;

        // update delivery adress if modified
        $delivery_result = $this->sync_delivery_address_object($user_id, (array) $remote);
        if (is_wp_error($delivery_result)) return $delivery_result;

        // TODO: should we use payload with PUT to update delivery address/contact person here?
        $update = todo;


        // If postalcode changed, it indicates they have new delivery address, so we create a new delivery address 
        //      by sending deliveryAddress on customer update
        $delivery_updated = false;
        if (is_array($delivery_result) && ($delivery_result['action'] ?? '') === 'create_new') {
            $delivery_updated = true;
            $update['deliveryAddress'] = $delivery_result['payload'];
        } elseif ($delivery_result === true) {
            $delivery_updated = true;
        }

        if (empty($update) && !$delivery_updated) {
            LH_Ttx_Logger::info('Tripletex: no customer changes to sync', [
                'user_id' => $user_id,
                'ttx_id'  => $ttx_id,
            ]);
            return true;
        } elseif (empty($update) && $delivery_updated) {
            LH_Ttx_Logger::info('Tripletex: synced user delivery address to tripletex', [
                'user_id' => $user_id,
                'ttx_id'  => $ttx_id,
            ]);
            return true;
        }


        // TODO: should we also add Contact people here?


        $res = ttx_customers_update($ttx_id, $update, null);
        if (is_wp_error($res)) return $res;

        LH_Ttx_Logger::info('Synced user to Tripletex', [
            'user_id' => $user_id,
            'ttx_id'  => $ttx_id,
            'fields'  => array_keys($update),
        ]);
        return true;
    }

    /* ------------------------- Helpers ------------------------- */

    /**
     * Persist link to Tripletex on the user and emit existing core hooks if present.
     */
    private function link_user_to_tripletex(int $user_id, int $ttx_id): void {
        update_user_meta($user_id, LH_TTX_META_TRIPLETEX_ID, $ttx_id);

        // Mirror LH core audit fields
        update_user_meta($user_id, 'tripletex_linked_by', get_current_user_id());
        update_user_meta($user_id, 'tripletex_linked_at', current_time('mysql'));

        // Maintain compatibility with LH core actions
        do_action('lavendelhygiene_tripletex_linked', $user_id, (string) $ttx_id, get_current_user_id());
    }

    /**
     * Minimal mapping from WP user meta to Tripletex customer payload.
     * Keep this boring and explicit; Tripletex expects specific fields.
     */
    private function map_user_to_tripletex_payload(int $user_id, array $opts = []): array {
        $for_create = (bool)($opts['for_create'] ?? false);

        $user   = get_user_by('id', $user_id);
        $orgnr  = preg_replace('/\D+/', '', (string) get_user_meta($user_id, 'orgnr', true));
        $use_ehf = (string) get_user_meta($user_id, 'use_ehf', true); // 'yes'|'no'

        // Billing
        $name        = (string) get_user_meta($user_id, 'billing_company', true);
        $email       = (string) get_user_meta($user_id, 'billing_email', true) ?: ($user->user_email ?? '');
        $phone       = (string) get_user_meta($user_id, 'billing_phone', true);

        $inv_addr_1  = (string) get_user_meta($user_id, 'billing_address_1', true);
        $inv_addr_2  = (string) get_user_meta($user_id, 'billing_address_2', true);
        $inv_post    = (string) get_user_meta($user_id, 'billing_postcode', true);
        $inv_city    = (string) get_user_meta($user_id, 'billing_city', true);
        $inv_country = (string) get_user_meta($user_id, 'billing_country', true) ?: 'NO';

        // Shipping
        $ship_addr_1  = (string) get_user_meta($user_id, 'shipping_address_1', true);
        $ship_addr_2  = (string) get_user_meta($user_id, 'shipping_address_2', true);
        $ship_post    = (string) get_user_meta($user_id, 'shipping_postcode', true);
        $ship_city    = (string) get_user_meta($user_id, 'shipping_city', true);
        $ship_country = (string) get_user_meta($user_id, 'shipping_country', true) ?: 'NO';

        // Shipping phone (custom)
        $ship_phone_raw = (string) get_user_meta($user_id, 'shipping_phone', true);
        $ship_phone_raw = $ship_phone_raw ?: $phone;

        $ttx_ship_phone = trim((string) $ship_phone_raw);
        if (empty($ttx_ship_phone)) {
            $ttx_ship_line1 = ($ship_addr_1 ?: $inv_addr_1) ?: null;
            $ttx_ship_line2 = ($ship_addr_2 ?: $inv_addr_2) ?: null;
        } else {
            $ttx_ship_line1 = ('Tlf ' . $ttx_ship_phone);
            $ttx_ship_line2 = ($ship_addr_1 ?: $inv_addr_1) ?: null;
        }
        

        // Tripletex Customer object (partial allowed on PUT)
        $payload = [
            'name'               => $for_create ? ($name ?: null) : null,
            'organizationNumber' => $for_create ? ($orgnr ?: null) : null,
            'email'              => $email ?: null,
            'invoiceEmail'       => $for_create ? ($email ?: null) : null,
            'invoiceSendMethod'  => $for_create ? ($use_ehf === 'yes' ? 'EHF' : 'EMAIL') : null,
            'isPrivateIndividual'=> $for_create ? (false) : null,
            'phoneNumber'        => $for_create ? ($phone ?: null) : null,
            'phoneNumberMobile'  => !$for_create ? ($phone ?: null) : null,
            'postalAddress'      => $for_create ? [
                'addressLine1' => $inv_addr_1 ?: null,
                'addressLine2' => $inv_addr_2 ?: null,
                'postalCode'   => $inv_post ?: null,
                'city'         => $inv_city ?: null,
                'country'      => [ 'isoAlpha2Code' => $inv_country ],
            ] : null,
            'deliveryAddress' => [
                'addressLine1' => $ttx_ship_line1,
                'addressLine2' => $ttx_ship_line2,
                'postalCode'   => ($ship_post   ?: $inv_post)   ?: null,
                'city'         => ($ship_city   ?: $inv_city)   ?: null,
                'country'      => [ 'isoAlpha2Code' => ($ship_country ?: $inv_country) ],
            ],
        ];

        // Remove nulls/empties
        $payload = array_filter($payload, static function($v) { return $v !== null && $v !== ''; });
        if (isset($payload['postalAddress'])) {
            $payload['postalAddress'] = array_filter($payload['postalAddress'], static fn($v) => $v !== null && $v !== '');
        }
        if (isset($payload['deliveryAddress'])) {
            $payload['deliveryAddress'] = array_filter($payload['deliveryAddress'], static fn($v) => $v !== null && $v !== '');
        }

        return $payload;
    }

    private function equals_ci_space(string $a, string $b): bool {
        return $this->norm_ci_space($a) === $this->norm_ci_space($b);
    }
    private function equals_strip_space(string $a, string $b): bool {
        return $this->norm_strip_space($a) === $this->norm_strip_space($b);
    }
    private function norm_ci_space(string $v): string {
        $v = preg_replace('/\s+/', ' ', trim((string) $v));
        return mb_strtolower($v, 'UTF-8');
    }
    private function norm_strip_space(string $v): string {
        return preg_replace('/\s+/', '', (string) $v);
    }

    /**
     * Update Tripletex deliveryAddress to prevent duplicates
     *
     * @return bool|array|\WP_Error
     *   - true  => updated existing deliveryAddress
     *   - false => no changes
     *   - array => ['action' => 'create_new', 'payload' => <deliveryAddress>]
     */
    private function sync_delivery_address_object(int $user_id, array $remote): bool|array|\WP_Error {
        $localDel  = $this->map_user_to_tripletex_payload($user_id, ['for_create' => false])['deliveryAddress'] ?? null;
        $remoteDel = (array) ($remote['deliveryAddress'] ?? []);
        $delId     = (int) ($remoteDel['id'] ?? 0);

        if (!is_array($localDel) || $delId <= 0) return false;

        $desired = (array) $localDel;

        // If postal code changed, create new address, do not overwrite existing deliveryAddress
        // TODO: if anything changed (normalized) in addressline1, postalcode, or city: create new address
        $localPost  = (string) ($desired['postalCode'] ?? '');
        $remotePost = (string) ($remoteDel['postalCode'] ?? '');
        if ($localPost !== '' && !$this->equals_strip_space($localPost, $remotePost)) {
            // send complete deliveryAddress
            $full = [
                'addressLine1' => (string) ($desired['addressLine1'] ?? ''),
                'addressLine2' => (string) ($desired['addressLine2'] ?? ''),
                'postalCode'   => (string) ($desired['postalCode'] ?? ''),
                'city'         => (string) ($desired['city'] ?? ''),
                'country'      => $desired['country'] ?? null,
            ];
            // remove empty values
            $full = array_filter($full, static fn($v) => $v !== null && $v !== '');
            if (isset($full['country']) && is_array($full['country'])) {
                $full['country'] = array_filter($full['country'], static fn($v) => $v !== null && $v !== '');
                if (empty($full['country'])) unset($full['country']);
            }

            // if we dont have enough data -> skip
            if (empty($full['addressLine1']) || empty($full['postalCode']) || empty($full['city'])) {
                return false;
            }

            LH_Ttx_Logger::info('Tripletex: postalCode changed; will create new deliveryAddress via customer update', [
                'user_id' => $user_id,
                'deliveryAddress_id' => $delId,
                'from_postalCode' => $remotePost,
                'to_postalCode'   => $localPost,
            ]);

            // TODO: just create new delivery address here?
            // important that it is linked with the customer

            return [
                'action'  => 'create_new',
                'payload' => $full,
            ];
        }



        // TODO: previously we updated existing delivery address on addressline changes here
        //  can keep logic, but should only update if addressLine2 has been changed

        $diff    = [];

        // field => comparator method
        $cmp = [
            'addressLine1' => 'equals_ci_space',
            'addressLine2' => 'equals_ci_space',
            'city'         => 'equals_ci_space',
            'postalCode'   => 'equals_strip_space',
        ];

        foreach ($cmp as $field => $fn) {
            $l = (string) ($desired[$field] ?? '');
            if ($l === '') continue;

            $r = (string) ($remoteDel[$field] ?? '');
            if (!$this->{$fn}($l, $r)) $diff[$field] = $l;
        }

        $lC = (string) ($desired['country']['isoAlpha2Code'] ?? '');
        if ($lC !== '') {
            $rC = (string) ($remoteDel['country']['isoAlpha2Code'] ?? '');
            if (!$this->equals_ci_space($lC, $rC)) $diff['country'] = ['isoAlpha2Code' => $lC];
        }

        if (!$diff) return false;

        $res = ttx_delivery_address_update($delId, $diff);
        if (is_wp_error($res)) return $res;

        LH_Ttx_Logger::info('Tripletex: updated deliveryAddress', [
            'user_id'            => $user_id,
            'deliveryAddress_id' => $delId,
            'fields'             => array_keys($diff),
        ]);

        return true;
    }
}