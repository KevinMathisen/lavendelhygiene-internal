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
     * Resolve the local customer links for an organisation number.
     * Multiple Tripletex customers may share the same organisation number.
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
            'has_conflict'       => false,
        ];
    }

    public function user_is_avdeling( int $user_id ): bool {
        $val = get_user_meta( $user_id, LavendelHygiene_Core::META_IS_AVDELING, true );
        return in_array( $val, [ '1', 1, true, 'yes', 'on' ], true );
    }

    /**
     * Resolve the existing Tripletex customer ID for an orgnr when unique.
     *
     * @return string Empty string when none or multiple customers exist.
     */
    public function find_existing_ttx_id_for_orgnr( string $orgnr, int $exclude_user_id = 0 ) {
        $state = $this->get_company_state_for_orgnr($orgnr, $exclude_user_id);
        return (string) $state['existing_ttx_id'];
    }

    /**
     * Return ranked local Tripletex customer candidates for a user.
     *
     * @return array{suggested_ttx_id:string,suggestion_reason:string,is_ambiguous:bool,candidates:array<int,array<string,mixed>>}
     */
    public function get_tripletex_candidates_for_user( int $user_id ): array {
        $orgnr = $this->get_user_orgnr( $user_id );
        $target_avdeling_name = trim( (string) get_user_meta(
            $user_id,
            LavendelHygiene_Core::META_AVDELING_NAME,
            true
        ) );
        $candidates_by_id = [];

        foreach ( $this->get_user_ids_for_orgnr( $orgnr, $user_id ) as $other_user_id ) {
            $ttx_id = $this->get_ttx_id( $other_user_id );
            if ( $ttx_id === '' ) continue;

            if ( ! isset( $candidates_by_id[ $ttx_id ] ) ) {
                $candidates_by_id[ $ttx_id ] = [
                    'ttx_id'                => $ttx_id,
                    'user_ids'              => [],
                    'avdeling_names'        => [],
                    'has_non_avdeling_user' => false,
                ];
            }

            $avdeling_name = trim( (string) get_user_meta(
                $other_user_id,
                LavendelHygiene_Core::META_AVDELING_NAME,
                true
            ) );
            $is_avdeling = $this->user_is_avdeling( $other_user_id );

            $candidates_by_id[ $ttx_id ]['user_ids'][] = $other_user_id;
            if ( $avdeling_name !== '' ) {
                $candidates_by_id[ $ttx_id ]['avdeling_names'][] = $avdeling_name;
            }
            if ( ! $is_avdeling && $avdeling_name === '' ) {
                $candidates_by_id[ $ttx_id ]['has_non_avdeling_user'] = true;
            }
        }

        $candidates = [];
        foreach ( $candidates_by_id as $candidate ) {
            $candidate['avdeling_names'] = array_values( array_unique( $candidate['avdeling_names'] ) );
            $candidate['score'] = $this->score_tripletex_candidate(
                $target_avdeling_name,
                $candidate['avdeling_names'],
                $candidate['has_non_avdeling_user']
            );
            $candidate['reason'] = $this->get_tripletex_candidate_reason(
                $target_avdeling_name,
                $candidate['avdeling_names'],
                $candidate['has_non_avdeling_user'],
                $candidate['score']
            );
            $candidates[] = $candidate;
        }

        usort( $candidates, static function( array $left, array $right ): int {
            if ( $left['score'] !== $right['score'] ) {
                return $right['score'] <=> $left['score'];
            }
            return (int) $left['ttx_id'] <=> (int) $right['ttx_id'];
        } );

        if ( empty( $candidates ) ) {
            return [
                'suggested_ttx_id'  => '',
                'suggestion_reason' => '',
                'is_ambiguous'      => false,
                'candidates'        => [],
            ];
        }

        $suggestion = $candidates[0];
        $is_ambiguous = count( $candidates ) > 1
            && $suggestion['score'] === $candidates[1]['score'];

        return [
            'suggested_ttx_id'  => (string) $suggestion['ttx_id'],
            'suggestion_reason' => count( $candidates ) === 1
                ? __( 'Only Tripletex customer found for this organisation number.', 'lavendelhygiene' )
                : (string) $suggestion['reason'],
            'is_ambiguous'      => $is_ambiguous,
            'candidates'        => $candidates,
        ];
    }


    /**
     * Validate assignment of a Tripletex ID to a user.
     *
    * Rules:
    * - Users with the same orgnr may use one or more Tripletex IDs.
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

    private function score_tripletex_candidate(
        string $target_avdeling_name,
        array $candidate_avdeling_names,
        bool $has_non_avdeling_user
    ): int {
        $target = $this->normalize_avdeling_name( $target_avdeling_name );

        if ( $target === '' ) {
            return $has_non_avdeling_user ? 100 : 0;
        }

        $best_score = 0;
        foreach ( $candidate_avdeling_names as $candidate_avdeling_name ) {
            $candidate = $this->normalize_avdeling_name( $candidate_avdeling_name );
            if ( $candidate === '' ) continue;

            if ( $target === $candidate ) {
                $best_score = max( $best_score, 100 );
                continue;
            }

            if ( str_contains( $target, $candidate ) || str_contains( $candidate, $target ) ) {
                $best_score = max( $best_score, 80 );
                continue;
            }

            $target_tokens = array_unique( explode( ' ', $target ) );
            $candidate_tokens = array_unique( explode( ' ', $candidate ) );
            $shared_tokens = array_intersect( $target_tokens, $candidate_tokens );
            $all_tokens = array_unique( array_merge( $target_tokens, $candidate_tokens ) );

            if ( ! empty( $all_tokens ) ) {
                $best_score = max(
                    $best_score,
                    (int) round( 70 * count( $shared_tokens ) / count( $all_tokens ) )
                );
            }
        }

        return $best_score;
    }

    private function get_tripletex_candidate_reason(
        string $target_avdeling_name,
        array $candidate_avdeling_names,
        bool $has_non_avdeling_user,
        int $score
    ): string {
        if ( $this->normalize_avdeling_name( $target_avdeling_name ) === '' && $has_non_avdeling_user ) {
            return __( 'Matched an existing user without an avdeling.', 'lavendelhygiene' );
        }

        if ( $score > 0 && ! empty( $candidate_avdeling_names ) ) {
            return sprintf(
                __( 'Best avdeling match: %s.', 'lavendelhygiene' ),
                implode( ', ', $candidate_avdeling_names )
            );
        }

        return __( 'No avdeling-name match found; selected as the first candidate.', 'lavendelhygiene' );
    }

    private function normalize_avdeling_name( string $value ): string {
        $value = strtolower( remove_accents( $value ) );
        $value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
        return trim( (string) preg_replace( '/\s+/', ' ', $value ) );
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