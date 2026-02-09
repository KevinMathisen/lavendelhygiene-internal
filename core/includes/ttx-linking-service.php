<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------- Service for setting local tripletex id for users ---------- */

class LavendelHygiene_TripletexLinkingService {
    public function get_ttx_id( int $user_id ): string {
        return (string) get_user_meta( $user_id, LavendelHygiene_Core::META_TRIPLETEX_ID, true );
    }

    public function sanitize_ttx_id( string $raw ): string {
        return preg_replace( '/\D+/', '', $raw );
    }

    public function is_ttx_id_unique( string $ttx_id, int $exclude_user_id = 0 ): bool {
        if ( $ttx_id === '' ) return true;
        $q = new WP_User_Query( [
            'number'   => 1,
            'fields'   => 'ID',
            'meta_key' => LavendelHygiene_Core::META_TRIPLETEX_ID,
            'meta_value' => $ttx_id,
            'meta_compare' => '=',
        ] );
        $found = $q->get_results();
        if ( empty( $found ) ) return true;
        $first = (int) $found[0];
        return $first === (int) $exclude_user_id;
    }

    public function set_ttx_id_wp( int $user_id, string $id, int $actor_user_id ): void {
        update_user_meta( $user_id, LavendelHygiene_Core::META_TRIPLETEX_ID, $id );
        update_user_meta( $user_id, LavendelHygiene_Core::META_TTX_LINKED_BY, $actor_user_id );
        update_user_meta( $user_id, LavendelHygiene_Core::META_TTX_LINKED_AT, current_time( 'mysql' ) );
        /**
         * Fires when a user is linked to a Tripletex customer ID.
         */
        do_action( 'lavendelhygiene_tripletex_linked', $user_id, $id, $actor_user_id );
    }

    public function clear_ttx_id_wp( int $user_id ): void {
        delete_user_meta( $user_id, LavendelHygiene_Core::META_TRIPLETEX_ID );
        delete_user_meta( $user_id, LavendelHygiene_Core::META_TTX_LINKED_BY );
        delete_user_meta( $user_id, LavendelHygiene_Core::META_TTX_LINKED_AT );
        do_action( 'lavendelhygiene_tripletex_unlinked', $user_id );
    }

    public function save_ttx_id_from_input( int $user_id, string $raw, int $actor_user_id ) {
        $id = $this->sanitize_ttx_id($raw);
        if ( $id === '' ) { $this->clear_ttx_id_wp($user_id); return 'cleared'; }
        if ( ! $this->is_ttx_id_unique($id, $user_id) ) {
            return new WP_Error( 'ttx_duplicate', __( 'Tripletex ID already linked to another user.', 'lavendelhygiene' ) );
        }
        $this->set_ttx_id_wp($user_id, $id, $actor_user_id);
        return 'saved';
    }

}