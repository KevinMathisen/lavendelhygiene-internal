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
        if ($user_id <= 0) return new WP_Error('user_id_invalid', __('Ugyldig bruker-ID.', 'lh-ttx'));

        $payload = $this->map_user_to_customer_create_payload($user_id);

        if (empty($payload['name'])) {
            return new WP_Error('payload_invalid', __('Kundenavn mangler.', 'lh-ttx'));
        }

        $res = ttx_customers_create($payload);
        if (is_wp_error($res)) return $res;
        $created_id = (int) $res['id'];

        $this->link_user_to_tripletex($user_id, $created_id);

        $delivery_id = (int) ($res['deliveryAddress']['id'] ?? 0);
        if ($delivery_id > 0) {
            lh_ttx_set_linked_delivery_address_id($user_id, $delivery_id);
        } else {
            LH_Ttx_Logger::error('Created Tripletex customer but deliveryAddress.id was missing', [
                'user_id' => $user_id, 'ttx_id' => $created_id, 'response' => $res,
            ]);
        }

        $contact_id = $this->ensure_and_get_contact_tripletex_id($user_id, $created_id);
        if (is_wp_error($contact_id)) {
            LH_Ttx_Logger::error('Created Tripletex customer but failed to create contact', [
                'user_id' => $user_id, 'ttx_id'  => $created_id, 'error' => $contact_id->get_error_message(),
                'data'    => $contact_id->get_error_data(),
            ]);
            return $contact_id;
        }

        LH_Ttx_Logger::info('Created Tripletex customer and linked user', [
            'user_id'             => $user_id,
            'ttx_id'              => $created_id,
            'contact_id'          => (int) $contact_id,
            'delivery_address_id' => (int) $delivery_id,
        ]);

        return (int) $created_id;
    }

    /**
     * Sync user changes to Tripletex.
     * Called from: on_profile_update, woocommerce_customer_save_address
     * 
     * Behavior:
     * - Always ensures contact and delivery address refs.
     * - For avdeling users, does NOT update the shared main Tripletex customer.
     * - For non-avdeling users, updates main customer email/mobile if changed.
     *
     * @param int $user_id
     * @return true|\WP_Error
     */
    public function sync_user(int $user_id) {
        if ($user_id <= 0) return new WP_Error('user_id_invalid', __('Ugyldig bruker-ID.', 'lh-ttx'));

        $ttx_id = lh_ttx_get_linked_tripletex_id($user_id);

        if (!$ttx_id) {
            // If no link, then we do nothing
            return new WP_Error('ttx_id_not_set', __('Kunde har ikke satt tripletex id.', 'lh-ttx'));
        }

        // ensure contact and delivery addresses are synced
        $contact_id = $this->ensure_and_get_contact_tripletex_id($user_id, $ttx_id);
        if (is_wp_error($contact_id)) return $contact_id;
        $delivery_id = $this->ensure_and_get_delivery_address_tripletex_id($user_id, $ttx_id);
        if (is_wp_error($delivery_id)) return $delivery_id;

        /*
        * Avdeling users share a Tripletex customer with other WP accounts.
        * Do not let an avdeling profile overwrite the shared company-level
        * email, phone, billing address, invoice settings, or default customer data.
        */
        if (lh_ttx_is_avdeling($user_id)) {
            LH_Ttx_Logger::info('Synced avdeling refs to Tripletex', [
                'user_id'             => $user_id,
                'ttx_id'              => $ttx_id,
                'contact_id'          => (int) $contact_id,
                'delivery_address_id' => (int) $delivery_id,
                'avdeling_name'       => lh_ttx_get_avdeling_name($user_id),
            ]);

            return true;
        }

        // For normal accounts (not avdeling), we also sync email and phone

        // get current tripletex customer data
        $remote = ttx_customers_get($ttx_id);
        if (is_wp_error($remote)) return $remote;

        $desired_customer = $this->map_user_to_customer_sync_payload($user_id);
        $update = $this->diff_main_customer_payload($desired_customer, (array) $remote);
        
        if (!$update) {
            LH_Ttx_Logger::info('Tripletex: no main customer changes to sync', [
                'user_id'             => $user_id,
                'ttx_id'              => $ttx_id,
                'contact_id'          => (int) $contact_id,
                'delivery_address_id' => (int) $delivery_id,
            ]);
            return true;
        }

        $res = ttx_customers_update($ttx_id, $update, null);
        if (is_wp_error($res)) return $res;

        LH_Ttx_Logger::info('Synced non-avdeling customer to Tripletex', [
            'user_id'             => $user_id,
            'ttx_id'              => $ttx_id,
            'contact_id'          => (int) $contact_id,
            'delivery_address_id' => (int) $delivery_id,
            'updates'              => $update,
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
     */
    private function map_user_to_customer_create_payload(int $user_id): array {
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
            'name'                => $name ?: null,
            'organizationNumber'  => $orgnr ?: null,
            'email'               => $email ?: null,
            'invoiceEmail'        => $email ?: null,
            'invoiceSendMethod'   => $use_ehf === 'yes' ? 'EHF' : 'EMAIL',
            'isPrivateIndividual' => false,
            'phoneNumber'         => $phone ?: null,
            'postalAddress'       => [
                'addressLine1' => $inv_addr_1 ?: null,
                'addressLine2' => $inv_addr_2 ?: null,
                'postalCode'   => $inv_post ?: null,
                'city'         => $inv_city ?: null,
                'country'      => ['isoAlpha2Code' => $inv_country],
            ],
            'deliveryAddress' => [
                'addressLine1' => $ttx_ship_line1,
                'addressLine2' => $ttx_ship_line2,
                'postalCode'   => ($ship_post   ?: $inv_post)   ?: null,
                'city'         => ($ship_city   ?: $inv_city)   ?: null,
                'country'      => [ 'isoAlpha2Code' => ($ship_country ?: $inv_country) ],
            ],
        ];

        return $this->filter_null_empty_recursive($payload);
    }

    /**
     * Map WP user meta to Tripletex Customer payload for SYNC.
     *
     * Used only for non-avdeling customer-level updates.
     * Do not include deliveryAddress here; delivery address is synced separately.
     */
    private function map_user_to_customer_sync_payload(int $user_id): array {
        $user = get_user_by('id', $user_id);

        $email = trim((string) get_user_meta($user_id, 'billing_email', true) ?: ($user->user_email ?? ''));
        $phone = trim((string) get_user_meta($user_id, 'billing_phone', true));

        return $this->filter_null_empty_recursive([
            'email'             => $email ?: null,
            'phoneNumberMobile' => $phone ?: null,
        ]);
    }

    /**
     * Minimal mapping from WP user meta to Tripletex contact payload.
     */
    private function map_user_to_contact_payload(int $user_id, int $ttx_id): array {
        $user   = get_user_by('id', $user_id);

        $first = trim((string) get_user_meta($user_id, 'billing_first_name', true));
        $last  = trim((string) get_user_meta($user_id, 'billing_last_name', true));
        if ($first === '') $first = trim((string) get_user_meta($user_id, 'first_name', true));
        if ($last === '') $last = trim((string) get_user_meta($user_id, 'last_name', true));
        
        $email = trim((string) get_user_meta($user_id, 'billing_email', true) ?: ($user->user_email ?? ''));
        $phone = trim((string) get_user_meta($user_id, 'billing_phone', true));

        // Tripletex Contact object (partial allowed on PUT)
        $payload = [
            'firstName'       => $first ?: null,
            'lastName'        => $last ?: null,
            'email'           => $email ?: null,
            'phoneNumberWork' => $phone ?: null,
            'customer'        => ['id' => $ttx_id],
        ];
        return $this->filter_null_empty_recursive($payload);
    }

    /**
     * Minimal mapping from WP user meta to Tripletex delivery address payload.
     */
    private function map_user_to_delivery_address_payload(int $user_id, int $ttx_id): array {
        $billing_phone = trim((string) get_user_meta($user_id, 'billing_phone', true));

        $ship_phone = trim((string) get_user_meta($user_id, 'shipping_phone', true));
        if ($ship_phone === '') $ship_phone = $billing_phone;

        $billing_addr_1 = trim((string) get_user_meta($user_id, 'billing_address_1', true));
        $billing_addr_2 = trim((string) get_user_meta($user_id, 'billing_address_2', true));
        $billing_post   = trim((string) get_user_meta($user_id, 'billing_postcode', true));
        $billing_city   = trim((string) get_user_meta($user_id, 'billing_city', true));
        $billing_country = trim((string) get_user_meta($user_id, 'billing_country', true));
        if ($billing_country === '') $billing_country = 'NO';

        $ship_addr_1 = trim((string) get_user_meta($user_id, 'shipping_address_1', true));
        $ship_addr_2 = trim((string) get_user_meta($user_id, 'shipping_address_2', true));
        $ship_post   = trim((string) get_user_meta($user_id, 'shipping_postcode', true));
        $ship_city   = trim((string) get_user_meta($user_id, 'shipping_city', true));
        $ship_country = trim((string) get_user_meta($user_id, 'shipping_country', true));
        if ($ship_country === '') $ship_country = $billing_country;

        $street_1 = $ship_addr_1 ?: $billing_addr_1;
        $street_2 = $ship_addr_2 ?: $billing_addr_2;

        if ($ship_phone !== '') {
            $line1 = 'Tlf ' . $ship_phone;
            $line2 = $street_1;
        } else {
            $line1 = $street_1;
            $line2 = $street_2;
        }

        $payload = [
            'addressLine1'   => $line1 ?: null,
            'addressLine2'   => $line2 ?: null,
            'postalCode'     => ($ship_post ?: $billing_post) ?: null,
            'city'           => ($ship_city ?: $billing_city) ?: null,
            'country'        => ['isoAlpha2Code' => $ship_country ?: 'NO'],
            'customerVendor' => ['id' => $ttx_id],
        ];

        return $this->filter_null_empty_recursive($payload);
    }

    /**
     * Ensure a Tripletex contact exists for this WP user and return its Tripletex ID.
     *
     * Email is treated as the primary lookup key.
     * If the saved contact ID has a different email, we do NOT overwrite that contact's email.
     *
     * @return int|\WP_Error
     */
    public function ensure_and_get_contact_tripletex_id(int $user_id, int $ttx_customer_id) {
        if ($user_id <= 0) return new WP_Error('user_id_invalid', __('Ugyldig bruker-ID.', 'lh-ttx'));
        if ($ttx_customer_id <= 0) return new WP_Error('ttx_customer_id_invalid', __('Ugyldig Tripletex-kunde-ID.', 'lh-ttx'));

        $desired = $this->map_user_to_contact_payload($user_id, $ttx_customer_id);
        $desired_email = (string) ($desired['email'] ?? '');

        if ($desired_email === '') return new WP_Error('contact_email_missing', __('Kontakt mangler e-post.', 'lh-ttx'));

        $saved_id = lh_ttx_get_linked_contact_tripletex_id($user_id);

        /*
        * 1. Try saved contact ID first.
        *    If email is unchanged, update name/phone if needed and return it.
        *    If email changed, do not update this contact's email; resolve by new email instead.
        */
        if ($saved_id > 0) {
            $remote = ttx_contact_get_by_id($saved_id);

            if (is_wp_error($remote)) {
                if (!$this->is_ttx_not_found_error($remote)) return $remote;

                // Saved ID points to a deleted/stale contact.
                lh_ttx_set_linked_contact_tripletex_id($user_id, 0);
            } else {
                $remote = (array) $remote;

                if ($this->contact_belongs_to_customer($remote, $ttx_customer_id)) {
                    $remote_email = (string) ($remote['email'] ?? '');

                    if ($this->same_email($remote_email, $desired_email)) {
                        $diff = $this->diff_contact_without_email($desired, $remote);
                        if ($diff) {
                            $res = ttx_contact_update($saved_id, $diff);
                            if (is_wp_error($res)) return $res;
                        }
                        return $saved_id;
                    }
                }
                // email of contact changes, create new instead
            }
        }

        // 2. Resolve by desired email + customer ID.
        $matches = ttx_contact_get($desired_email, $ttx_customer_id);
        if (is_wp_error($matches)) return $matches;

        $matches = is_array($matches) ? array_values($matches) : [];
        $matches = array_values(array_filter($matches, function ($row) use ($ttx_customer_id) {
            return $this->contact_belongs_to_customer((array) $row, $ttx_customer_id);
        }));

        // 3. If multiple email matches exist, narrow by last name.
        if (count($matches) > 1) {
            $matches = $this->filter_contacts_by_last_name($matches, (string) ($desired['lastName'] ?? ''));
        }

        if (count($matches) > 1) {
            return new WP_Error(
                'ttx_contact_ambiguous', 
                __('Fant flere Tripletex-kontakter med samme e-post.', 'lh-ttx'),
                ['email' => $desired_email, 'customer_id' => $ttx_customer_id, 'matches' => $matches]
            );
        }

        // 4. One matching contact: update name/phone, store ID, return ID.
        if (count($matches) === 1) {
            $remote = (array) $matches[0];
            $contact_id = (int) ($remote['id'] ?? 0);

            if ($contact_id <= 0) return new WP_Error('ttx_contact_id_missing', __('Fant kontakt uten gyldig ID.', 'lh-ttx'));

            $diff = $this->diff_contact_without_email($desired, $remote);
            if ($diff) {
                $res = ttx_contact_update($contact_id, $diff);
                if (is_wp_error($res)) return $res;
            }
            lh_ttx_set_linked_contact_tripletex_id($user_id, $contact_id);
            return $contact_id;
        }

        // 5. No matching contact: create it.
        $created = ttx_contact_create($desired);
        if (is_wp_error($created)) return $created;

        $contact_id = (int) ($created['id'] ?? 0);

        if ($contact_id <= 0) {
            return new WP_Error('ttx_contact_create_missing_id',
                __('Tripletex returnerte ikke kontakt-ID.', 'lh-ttx'), ['response' => $created]);
        }

        lh_ttx_set_linked_contact_tripletex_id($user_id, $contact_id);
        return $contact_id;
    }

    /**
     * Ensure a Tripletex delivery address exists for this WP user and return its Tripletex ID.
     *
     * Non-avdeling:
     * - Prefer saved delivery address ID.
     * - Else use customer.deliveryAddress.id.
     * - If customer has no default delivery address, create it through PUT /customer
     *   so Tripletex sets it as the customer's default delivery address.
     *
     * Avdeling:
     * - Prefer saved delivery address ID.
     * - Else search matching delivery address.
     * - Else create via POST /deliveryAddress.
     *
     * @return int|\WP_Error
     */
    public function ensure_and_get_delivery_address_tripletex_id(int $user_id, int $ttx_customer_id) {
        if ($user_id <= 0) return new WP_Error('user_id_invalid', __('Ugyldig bruker-ID.', 'lh-ttx'));
        if ($ttx_customer_id <= 0) return new WP_Error('ttx_customer_id_invalid', __('Ugyldig Tripletex-kunde-ID.', 'lh-ttx'));

        $desired = $this->map_user_to_delivery_address_payload($user_id, $ttx_customer_id);

        // 1. Try saved delivery address ID first.
        $saved_id = lh_ttx_get_linked_delivery_address_id($user_id);

        if ($saved_id > 0) {
            $remote = ttx_delivery_address_get($saved_id);

            if (is_wp_error($remote)) {
                if (!$this->is_ttx_not_found_error($remote)) return $remote;

                // Saved ID points to a deleted/stale address.
                lh_ttx_set_linked_delivery_address_id($user_id, 0);
            } else {
                $remote = (array) $remote;

                if ($this->delivery_address_belongs_to_customer($remote, $ttx_customer_id)) {
                    $diff = $this->diff_delivery_address_payload($desired, $remote);
                    if ($diff) {
                        $res = ttx_delivery_address_update($saved_id, $diff);
                        if (is_wp_error($res)) return $res;
                    }
                    return $saved_id;
                }

                // Saved ID belongs to another customer (unlikely but possible)
                lh_ttx_set_linked_delivery_address_id($user_id, 0);
            }
        }

        // 2. Non-avdeling: use or create customer's default delivery address.
        if (!lh_ttx_is_avdeling($user_id)) {
            $customer = ttx_customers_get($ttx_customer_id);

            if (is_wp_error($customer)) return $customer;

            $remote_default = (array) ($customer['deliveryAddress'] ?? []);
            $default_id = (int) ($remote_default['id'] ?? 0);

            if ($default_id > 0) {
                $diff = $this->diff_delivery_address_payload($desired, $remote_default);
                if ($diff) {
                    $res = ttx_delivery_address_update($default_id, $diff);
                    if (is_wp_error($res)) return $res;
                }
                lh_ttx_set_linked_delivery_address_id($user_id, $default_id);
                return $default_id;
            }

            // No customer default delivery address exists.
            // Create through PUT /customer so this address becomes the default delivery address.
            $created_default_id = $this->create_customer_default_delivery_address_and_get_id(
                $ttx_customer_id,
                $desired
            );

            if (is_wp_error($created_default_id)) return $created_default_id;

            lh_ttx_set_linked_delivery_address_id($user_id, (int) $created_default_id);
            return (int) $created_default_id;
        }

        // 3. Avdeling: find matching address, else create independent deliveryAddress object.
        $match_line = (string) ($desired['addressLine2'] ?? '');
        if ($match_line === '') {
            $match_line = (string) ($desired['addressLine1'] ?? '');
        }

        $match = ttx_delivery_address_get_without_id(
            (string) ($desired['postalCode'] ?? ''),
            (string) ($desired['city'] ?? ''), 
            $ttx_customer_id, $match_line);

        if (is_wp_error($match)) return $match;

        if (is_array($match) && !empty($match)) {
            $matched_id = (int) ($match['id'] ?? 0);

            if ($matched_id <= 0) {
                return new WP_Error(
                    'ttx_delivery_address_id_missing',
                    __('Fant leveringsadresse uten gyldig ID.', 'lh-ttx'),
                    ['match' => $match]
                );
            }

            $remote = ttx_delivery_address_get(
                $matched_id,
                'id,addressLine1,addressLine2,postalCode,city,country(isoAlpha2Code),customerVendor(id)'
            );

            if (is_wp_error($remote)) return $remote;

            $diff = $this->diff_delivery_address_payload($desired, (array) $remote);
            if ($diff) {
                $res = ttx_delivery_address_update($matched_id, $diff);
                if (is_wp_error($res)) return $res;
            }
            lh_ttx_set_linked_delivery_address_id($user_id, $matched_id);
            return $matched_id;
        }

        // No match for avdeling: create a new /deliveryAddress object.
        $created = ttx_delivery_address_create($desired);
        if (is_wp_error($created)) return $created;

        $created_id = (int) ($created['id'] ?? 0);

        if ($created_id <= 0) {
            return new WP_Error('ttx_delivery_address_create_missing_id',
                __('Tripletex returnerte ikke leveringsadresse-ID.', 'lh-ttx'), ['response' => $created]);
        }

        lh_ttx_set_linked_delivery_address_id($user_id, $created_id);
        return $created_id;
    }

    /**
     * Create a customer's default delivery address by PUT /customer/{id}
     * and return the new deliveryAddress.id.
     *
     * @return int|\WP_Error
     */
    private function create_customer_default_delivery_address_and_get_id(int $ttx_customer_id, array $delivery_payload) {
        unset($delivery_payload['customerVendor']);
        $required = ['addressLine1', 'postalCode', 'city'];
        foreach ($required as $field) {
            if (empty($delivery_payload[$field])) {
                return new WP_Error('ttx_delivery_address_incomplete',
                    __('Leveringsadresse mangler påkrevd felt.', 'lh-ttx'),
                    ['field' => $field, 'customer_id' => $ttx_customer_id, 'payload' => $delivery_payload]
                );
            }
        }

        $res = ttx_customers_update($ttx_customer_id, ['deliveryAddress' => $delivery_payload]);

        if (is_wp_error($res)) return $res;

        $delivery_id = (int) ($res['deliveryAddress']['id'] ?? 0);
        if ($delivery_id > 0) return $delivery_id;

        return new WP_Error(
                'ttx_customer_delivery_address_missing_id',
                __('Tripletex returnerte ikke ID for kundens leveringsadresse.', 'lh-ttx'),
                ['put_response' => $res, 'customer_id' => $ttx_customer_id]
        );
    }

    /**
     * Diff desired customer-level payload against remote Tripletex customer.
     *
     * @param array $desired
     * @param array $remote
     * @return array
     */
    private function diff_main_customer_payload(array $desired, array $remote): array {
        $diff = [];

        $local_email = (string) ($desired['email'] ?? '');
        if ($local_email !== '') {
            $remote_email = (string) ($remote['email'] ?? '');

            if ($this->norm_email($local_email) !== $this->norm_email($remote_email)) {
                $diff['email'] = $local_email;
            }
        }

        $local_mobile = (string) ($desired['phoneNumberMobile'] ?? '');
        if ($local_mobile !== '') {
            $remote_mobile = (string) ($remote['phoneNumberMobile'] ?? '');

            if ($this->norm_phone($local_mobile) !== $this->norm_phone($remote_mobile)) {
                $diff['phoneNumberMobile'] = $local_mobile;
            }
        }

        return $diff;
    }

    /**
     * Diff desired Contact payload against remote Contact.
     * Never includes email in the update payload.
     */
    private function diff_contact_without_email(array $desired, array $remote): array {
        $diff = [];

        foreach (['firstName', 'lastName'] as $field) {
            $local = (string) ($desired[$field] ?? '');
            if ($local === '') continue;

            $remote_value = (string) ($remote[$field] ?? '');

            if ($this->norm_space($local) !== $this->norm_space($remote_value)) {
                $diff[$field] = $local;
            }
        }

        $local_phone = (string) ($desired['phoneNumberWork'] ?? '');
        if ($local_phone !== '') {
            $remote_phone = (string) ($remote['phoneNumberWork'] ?? '');

            if ($this->norm_phone($local_phone) !== $this->norm_phone($remote_phone)) {
                $diff['phoneNumberWork'] = $local_phone;
            }
        }

        return $diff;
    }

    /**
     * Diff desired DeliveryAddress payload against remote DeliveryAddress.
     * Does not include customerVendor in the update payload.
     */
    private function diff_delivery_address_payload(array $desired, array $remote): array {
        $diff = [];

        foreach (['addressLine1', 'addressLine2', 'city'] as $field) {
            $local = (string) ($desired[$field] ?? '');
            if ($local === '') continue;

            $remote_value = (string) ($remote[$field] ?? '');

            if ($this->norm_ci_space($local) !== $this->norm_ci_space($remote_value)) {
                $diff[$field] = $local;
            }
        }

        $local_post = (string) ($desired['postalCode'] ?? '');
        if ($local_post !== '') {
            $remote_post = (string) ($remote['postalCode'] ?? '');

            if ($this->norm_strip_space($local_post) !== $this->norm_strip_space($remote_post)) {
                $diff['postalCode'] = $local_post;
            }
        }

        $local_country = (string) ($desired['country']['isoAlpha2Code'] ?? '');
        if ($local_country !== '') {
            $remote_country = (string) ($remote['country']['isoAlpha2Code'] ?? '');

            if ($this->norm_ci_space($local_country) !== $this->norm_ci_space($remote_country)) {
                $diff['country'] = ['isoAlpha2Code' => $local_country];
            }
        }

        return $diff;
    }

    private function contact_belongs_to_customer(array $contact, int $ttx_customer_id): bool {
        $remote_customer_id = (int) ($contact['customer']['id'] ?? 0);
        return $remote_customer_id === $ttx_customer_id;
    }

    /**
     * If customerVendor is present, enforce it.
     * If Tripletex does not return customerVendor for a fetched deliveryAddress,
     * accept the address rather than failing.
     */
    private function delivery_address_belongs_to_customer(array $address, int $ttx_customer_id): bool {
        $remote_customer_id = (int) ($address['customerVendor']['id'] ?? 0);
        if ($remote_customer_id <= 0) return true;
        return $remote_customer_id === $ttx_customer_id;
    }

    private function filter_contacts_by_last_name(array $contacts, string $last_name): array {
        $needle = $this->norm_ci_space($last_name);
        if ($needle === '') return $contacts;
        return array_values(array_filter($contacts, function ($contact) use ($needle) {
            $contact = (array) $contact;
            return $this->norm_ci_space((string) ($contact['lastName'] ?? '')) === $needle;
        }));
    }

    private function same_email(string $a, string $b): bool {
        return $this->norm_email($a) === $this->norm_email($b);
    }

    private function norm_email(string $v): string {
        return mb_strtolower(trim($v), 'UTF-8');
    }

    private function norm_space(string $v): string {
        return preg_replace('/\s+/', ' ', trim((string) $v));
    }

    private function norm_ci_space(string $v): string {
        return mb_strtolower($this->norm_space($v), 'UTF-8');
    }

    private function norm_strip_space(string $v): string {
        return preg_replace('/\s+/', '', trim((string) $v));
    }

    private function norm_phone(string $v): string {
        return preg_replace('/[\s\-\.\(\)]+/', '', trim((string) $v));
    }

    private function is_ttx_not_found_error($err): bool {
        if (!is_wp_error($err)) {
            return false;
        }

        $data = $err->get_error_data();

        return is_array($data) && (int) ($data['status'] ?? 0) === 404;
    }

    private function filter_null_empty_recursive(array $arr): array {
        foreach ($arr as $key => $value) {
            if (is_array($value)) {
                $value = $this->filter_null_empty_recursive($value);

                if ($value === []) {
                    unset($arr[$key]);
                    continue;
                }

                $arr[$key] = $value;
                continue;
            }

            if ($value === null || $value === '') {
                unset($arr[$key]);
            }
        }

        return $arr;
    }
}