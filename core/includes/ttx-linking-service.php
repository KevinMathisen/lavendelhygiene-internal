<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------- Service for setting local tripletex id for users ---------- */

class LavendelHygiene_TripletexLinkingService {
    public function get_ttx_id( int $user_id ): string {
        return $this->sanitize_ttx_id(
            (string) get_user_meta($user_id, LavendelHygiene_Core::META_TRIPLETEX_ID, true)
        );
    }

    public function get_user_orgnr( int $user_id ): string {
        return $this->sanitize_orgnr(
            (string) get_user_meta($user_id, LavendelHygiene_Core::META_ORGNR, true)
        );
    }

    public function sanitize_ttx_id( string $raw ): string {
        return preg_replace( '/\D+/', '', $raw );
    }

    public function sanitize_orgnr( string $raw ): string {
        return preg_replace( '/\D+/', '', $raw );
    }

    /**
     * Get all WP user IDs registered with an organisation number.
     *
     * @return int[]
     */
    public function get_user_ids_for_orgnr(string $orgnr, int $exclude_user_id = 0): array {
        $orgnr = $this->sanitize_orgnr( $orgnr );
        if ( $orgnr === '' ) { return []; }

        $query = new WP_User_Query( [
            'number'       => -1,
            'fields'       => 'ID',
            'meta_key'     => LavendelHygiene_Core::META_ORGNR,
            'meta_value'   => $orgnr,
            'meta_compare' => '=',
        ] );

        $ids = array_map( 'intval', $query->get_results() );

        if ( $exclude_user_id > 0 ) {
            $ids = array_values(
                array_filter(
                    $ids,
                    static fn( int $id ): bool => $id !== $exclude_user_id
                )
            );
        }
        return $ids;
    }

    /**
     * Resolve the local company state for an organisation number.
     *
     * Returns:
     * [
     *   'orgnr'             => string,
     *   'user_ids'          => int[],
     *   'ttx_ids'           => string[],
     *   'existing_ttx_id'   => string,
     *   'has_existing_users'=> bool,
     *   'has_conflict'      => bool,
     * ]
     */
    public function get_company_state_for_orgnr(string $orgnr, int $exclude_user_id = 0): array {
        $orgnr = $this->sanitize_orgnr( $orgnr );
        $user_ids = $this->get_user_ids_for_orgnr($orgnr, $exclude_user_id);
        $ttx_ids = [];

        foreach ( $user_ids as $user_id ) {
            $ttx_id = $this->get_ttx_id( $user_id );

            if ( $ttx_id !== '' ) $ttx_ids[] = $ttx_id;
        }

        $ttx_ids = array_values( array_unique( $ttx_ids ) );

        return [
            'orgnr'              => $orgnr,
            'user_ids'           => $user_ids,
            'ttx_ids'            => $ttx_ids,
            'existing_ttx_id'    => count( $ttx_ids ) === 1 ? $ttx_ids[0] : '',
            'has_existing_users' => ! empty( $user_ids ),
            'has_conflict'       => count( $ttx_ids ) > 1,
        ];
    }

    public function user_is_avdeling( int $user_id ): bool {
        $val = get_user_meta( $user_id, LavendelHygiene_Core::META_IS_AVDELING, true );
        return in_array( $val, [ '1', 1, true, 'yes', 'on' ], true );
    }

    /**
     * Resolve the existing Tripletex customer ID for an orgnr.
     *
     * @return string|\WP_Error Empty string when none exists.
     */
    public function find_existing_ttx_id_for_orgnr( string $orgnr, int $exclude_user_id = 0 ) {
        $state = $this->get_company_state_for_orgnr($orgnr, $exclude_user_id);

        if ( $state['has_conflict'] ) {
            return new WP_Error(
                'ttx_orgnr_conflict',
                __('Brukere med samme organisasjonsnummer er knyttet til forskjellige Tripletex-kunder.','lavendelhygiene'),
                $state
            );
        }
        return (string) $state['existing_ttx_id'];
    }


    /**
     * Validate assignment of a Tripletex ID to a user.
     *
     * Rules:
     * - Users with the same orgnr may share one Tripletex ID.
     * - The orgnr may not already map to another Tripletex ID.
     * - The Tripletex ID may not be used by another orgnr.
     *
     * @return true|\WP_Error
     */
    public function validate_ttx_id_for_user( int $user_id, string $ttx_id ) {
        if ( $user_id <= 0 ) return new WP_Error('user_id_invalid', __( 'Ugyldig bruker-ID.', 'lavendelhygiene' ));
        $ttx_id = $this->sanitize_ttx_id( $ttx_id );
        if ( $ttx_id === '' ) return true;

        $orgnr = $this->get_user_orgnr( $user_id );
        if ( $orgnr === '' ) return new WP_Error('orgnr_missing', __('Brukeren mangler organisasjonsnummer.', 'lavendelhygiene'));

        // same orgnr cannot already map to another Tripletex ID.
        $company_state = $this->get_company_state_for_orgnr($orgnr, $user_id);

        if ( $company_state['has_conflict'] ) {
            return new WP_Error(
                'ttx_orgnr_conflict',
                __(
                    'Organisasjonsnummeret er allerede knyttet til flere forskjellige Tripletex-ID-er. Dette må rettes manuelt.',
                    'lavendelhygiene'
                ),
                $company_state
            );
        }
        $existing_company_ttx_id = (string) $company_state['existing_ttx_id'];

        if ($existing_company_ttx_id !== '' && $existing_company_ttx_id !== $ttx_id) {
            return new WP_Error(
                'ttx_orgnr_already_linked',
                sprintf(
                    __('Organisasjonsnummeret er allerede knyttet til Tripletex-ID %s.', 'lavendelhygiene'),
                    $existing_company_ttx_id
                ),
                [
                    'orgnr'                 => $orgnr,
                    'existing_ttx_id'       => $existing_company_ttx_id,
                    'attempted_ttx_id'      => $ttx_id,
                    'existing_user_ids'     => $company_state['user_ids'],
                ]
            );
        }

        // the same Tripletex ID cannot belong to another orgnr.
        $query = new WP_User_Query( [
            'number'       => -1,
            'fields'       => 'ID',
            'meta_key'     => LavendelHygiene_Core::META_TRIPLETEX_ID,
            'meta_value'   => $ttx_id,
            'meta_compare' => '=',
        ] );

        foreach ( array_map( 'intval', $query->get_results() ) as $other_user_id ) {
            if ( $other_user_id === $user_id ) continue;

            $other_orgnr = $this->get_user_orgnr( $other_user_id );

            if ( $other_orgnr === '' || $other_orgnr !== $orgnr ) {
                return new WP_Error(
                    'ttx_id_used_by_other_orgnr',
                    __('Tripletex-ID-en er allerede knyttet til et annet organisasjonsnummer.', 'lavendelhygiene'),
                    [
                        'ttx_id'        => $ttx_id,
                        'user_id'       => $user_id,
                        'orgnr'         => $orgnr,
                        'other_user_id' => $other_user_id,
                        'other_orgnr'   => $other_orgnr,
                    ]
                );
            }
        }

        return true;
    }

    /**
     * Validate and save a Tripletex customer link.
     *
     * @return true|\WP_Error
     */
    public function set_ttx_id_wp( int $user_id, string $id, int $actor_user_id ) {
        $id = $this->sanitize_ttx_id( $id );
        $valid = $this->validate_ttx_id_for_user($user_id, $id);
        if ( is_wp_error( $valid ) ) return $valid;

        $old_id = $this->get_ttx_id( $user_id );

        update_user_meta( $user_id, LavendelHygiene_Core::META_TRIPLETEX_ID, $id );
        update_user_meta( $user_id, LavendelHygiene_Core::META_TTX_LINKED_BY, $actor_user_id );
        update_user_meta( $user_id, LavendelHygiene_Core::META_TTX_LINKED_AT, current_time( 'mysql' ) );

        // contact and delivery IDs must be removed if ttx id modified
        if ( $old_id !== '' && $old_id !== $id ) {
            delete_user_meta( $user_id, '_tripletex_contact_id' );
            delete_user_meta( $user_id, '_tripletex_delivery_address_id' );
        }

        // Fires when a user is linked to a Tripletex customer ID, tripletex-api listends to this
        do_action( 'lavendelhygiene_tripletex_linked', $user_id, $id, $actor_user_id );

        return true;
    }

    public function clear_ttx_id_wp( int $user_id ): void {
        delete_user_meta( $user_id, LavendelHygiene_Core::META_TRIPLETEX_ID );
        delete_user_meta( $user_id, LavendelHygiene_Core::META_TTX_LINKED_BY );
        delete_user_meta( $user_id, LavendelHygiene_Core::META_TTX_LINKED_AT );

        delete_user_meta( $user_id, '_tripletex_contact_id' );
        delete_user_meta( $user_id, '_tripletex_delivery_address_id' );

        do_action( 'lavendelhygiene_tripletex_unlinked', $user_id );
    }

    public function save_ttx_id_from_input( int $user_id, string $raw, int $actor_user_id ) {
        $id = $this->sanitize_ttx_id($raw);
        if ( $id === '' ) { $this->clear_ttx_id_wp($user_id); return 'cleared'; }
    
        $result = $this->set_ttx_id_wp($user_id, $id, $actor_user_id);
        if ( is_wp_error( $result ) ) return $result;

        return 'saved';
    }

}