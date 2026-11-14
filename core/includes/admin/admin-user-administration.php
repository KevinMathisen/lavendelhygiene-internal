<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------- Admin: consolidated user administration ---------- */

class LavendelHygiene_AdminUserAdministration {
    private const PER_PAGE = 25;
    private array $customer_counts = [];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
    }

    public function admin_menu() {
        if ( ! current_user_can( 'list_users' ) && ! current_user_can( 'promote_users' ) ) return;

        add_users_page(
            __( 'LH: User Administration', 'lavendelhygiene' ),
            __( 'LH: User Administration', 'lavendelhygiene' ),
            'read',
            'lavendelhygiene-user-administration',
            [ $this, 'render' ]
        );
    }

    public function render() {
        $this->assert_access();

        $filters = $this->get_filters();
        $users = $this->get_filtered_users( $filters );
        $total = count( $users );
        $page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
        $pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
        $page = min( $page, $pages );
        $page_users = array_slice( $users, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'LH: User Administration', 'lavendelhygiene' ); ?></h1>

            <form method="get" style="margin:16px 0;">
                <input type="hidden" name="page" value="lavendelhygiene-user-administration">
                <input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search name, email, username, company, org.nr, or Tripletex ID', 'lavendelhygiene' ); ?>" class="regular-text">
                <select name="sort">
                    <?php $this->option( 'sort', $filters['sort'], 'user', __( 'Sort: User', 'lavendelhygiene' ) ); ?>
                    <?php $this->option( 'sort', $filters['sort'], 'company', __( 'Sort: Company', 'lavendelhygiene' ) ); ?>
                    <?php $this->option( 'sort', $filters['sort'], 'orgnr', __( 'Sort: Org. number', 'lavendelhygiene' ) ); ?>
                    <?php $this->option( 'sort', $filters['sort'], 'last_login', __( 'Sort: Last login', 'lavendelhygiene' ) ); ?>
                    <?php $this->option( 'sort', $filters['sort'], 'created', __( 'Sort: Created', 'lavendelhygiene' ) ); ?>
                </select>
                <select name="status">
                    <?php $this->option( 'status', $filters['status'], 'all', __( 'All approval statuses', 'lavendelhygiene' ) ); ?>
                    <?php $this->option( 'status', $filters['status'], 'approved', __( 'Approved', 'lavendelhygiene' ) ); ?>
                    <?php $this->option( 'status', $filters['status'], 'pending', __( 'Pending', 'lavendelhygiene' ) ); ?>
                    <?php $this->option( 'status', $filters['status'], 'denied', __( 'Denied', 'lavendelhygiene' ) ); ?>
                    <?php $this->option( 'status', $filters['status'], 'missing', __( 'Missing status', 'lavendelhygiene' ) ); ?>
                </select>
                <select name="role">
                    <?php $this->option( 'role', $filters['role'], 'all', __( 'All roles', 'lavendelhygiene' ) ); ?>
                    <?php foreach ( $this->get_role_options() as $role => $label ) $this->option( 'role', $filters['role'], $role, $label ); ?>
                </select>
                <span style="display:inline-flex;align-items:center;gap:8px;margin:0 8px 0 0;white-space:nowrap;">
                    <label><input type="checkbox" name="avdeling" value="1" <?php checked( $filters['avdeling'], true ); ?>> <?php esc_html_e( 'Avdeling', 'lavendelhygiene' ); ?></label>
                    <label><input type="checkbox" name="multiple_linked" value="1" <?php checked( $filters['multiple_linked'], true ); ?>> <?php esc_html_e( 'Multiple linked to same company', 'lavendelhygiene' ); ?></label>
                    <label><input type="checkbox" name="missing_tripletex" value="1" <?php checked( $filters['missing_tripletex'], true ); ?>> <?php esc_html_e( 'Missing Tripletex ID', 'lavendelhygiene' ); ?></label>
                    <label><input type="checkbox" name="missing_contact" value="1" <?php checked( $filters['missing_contact'], true ); ?>> <?php esc_html_e( 'Missing contact ID', 'lavendelhygiene' ); ?></label>
                    <label><input type="checkbox" name="missing_delivery" value="1" <?php checked( $filters['missing_delivery'], true ); ?>> <?php esc_html_e( 'Missing delivery ID', 'lavendelhygiene' ); ?></label>
                </span>
                <button class="button button-primary"><?php esc_html_e( 'Filter', 'lavendelhygiene' ); ?></button>
            </form>

            <p><?php echo esc_html( sprintf( _n( '%d user found', '%d users found', $total, 'lavendelhygiene' ), $total ) ); ?></p>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'User', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'Company', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'Status / relationship', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'Tripletex', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'Last login', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'Discounts', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'lavendelhygiene' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( empty( $page_users ) ) : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No users matched the filters.', 'lavendelhygiene' ); ?></td></tr>
                <?php else : foreach ( $page_users as $user ) : $this->render_row( $user ); endforeach; endif; ?>
                </tbody>
            </table>

            <?php if ( $pages > 1 ) : ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php echo wp_kses_post( paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages, 'add_args' => $this->pagination_args( $filters ) ] ) ); ?>
                </div></div>
            <?php endif; ?>
        </div>
        <script>
        document.addEventListener('click', function(event) {
            var button = event.target.closest('.lh-user-details-toggle');
            if (!button) return;
            var details = document.getElementById(button.getAttribute('aria-controls'));
            var expanded = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            details.hidden = expanded;
        });
        </script>
        <?php
    }

    private function render_row( WP_User $user ) {
        $data = $this->user_data( $user, $this->customer_counts );
        $details_id = 'lh-user-details-' . (int) $user->ID;
        ?>
        <tr>
            <td>
                <strong><?php echo esc_html( $data['name'] ); ?></strong><br>
                <a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->user_login ); ?></a><br>
                <small><?php echo esc_html( $user->user_email ); ?></small>
            </td>
            <td><strong><?php echo esc_html( $data['company'] ?: '—' ); ?></strong><br><small><?php echo esc_html( $data['orgnr'] ?: '—' ); ?></small></td>
            <td><?php echo esc_html( $data['status'] ); ?><br><small><?php echo esc_html( $data['relationship_label'] ); ?></small><br><small><?php echo esc_html( $data['user_type_label'] ); ?></small></td>
            <td><?php echo $data['ttx_id'] > 0 ? esc_html( (string) $data['ttx_id'] ) : '—'; ?></td>
            <td><?php echo esc_html( $data['last_login'] ?: '—' ); ?></td>
            <td><?php echo esc_html( sprintf( __( '%d discounts', 'lavendelhygiene' ), $data['discount_count'] ) ); ?></td>
            <td>
                <button type="button" class="button button-primary lh-user-details-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $details_id ); ?>"><?php esc_html_e( 'Details', 'lavendelhygiene' ); ?></button>
                <a class="button" href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php esc_html_e( 'Edit', 'lavendelhygiene' ); ?></a>
                <?php if ( $data['ttx_id'] > 0 ) : ?>
                    <a class="button" href="<?php echo esc_url( admin_url( 'users.php?page=lavendelhygiene-discounts&user_id=' . $user->ID ) ); ?>"><?php esc_html_e( 'Discounts', 'lavendelhygiene' ); ?></a>
                <?php endif; ?>
            </td>
        </tr>
        <tr id="<?php echo esc_attr( $details_id ); ?>" hidden>
            <td colspan="7"><strong><?php esc_html_e( 'Details', 'lavendelhygiene' ); ?></strong><br>
                <?php echo esc_html( sprintf( __( 'Roles: %s', 'lavendelhygiene' ), implode( ', ', $data['roles'] ) ?: '—' ) ); ?><br>
                <?php echo esc_html( sprintf( __( 'Created: %s', 'lavendelhygiene' ), $data['created'] ?: '—' ) ); ?><br>
                <?php echo esc_html( sprintf( __( 'Last login: %s', 'lavendelhygiene' ), $data['last_login'] ?: '—' ) ); ?><br>
                <?php echo esc_html( sprintf( __( 'Tripletex customer ID: %s', 'lavendelhygiene' ), $data['ttx_id'] > 0 ? $data['ttx_id'] : '—' ) ); ?><br>
                <?php echo esc_html( sprintf( __( 'Tripletex contact ID: %s', 'lavendelhygiene' ), $data['contact_id'] > 0 ? $data['contact_id'] : '—' ) ); ?><br>
                <?php echo esc_html( sprintf( __( 'Tripletex delivery address ID: %s', 'lavendelhygiene' ), $data['delivery_id'] > 0 ? $data['delivery_id'] : '—' ) ); ?><br>
                <?php echo esc_html( sprintf( __( 'Discounts: %d', 'lavendelhygiene' ), $data['discount_count'] ) ); ?>
            </td>
        </tr>
        <?php
    }

    private function get_filtered_users( array $filters ): array {
        $users = get_users( [ 'number' => -1, 'orderby' => 'registered', 'order' => 'DESC' ] );
        $this->customer_counts = [];
        foreach ( $users as $user ) {
            $id = (int) get_user_meta( $user->ID, LavendelHygiene_Core::META_TRIPLETEX_ID, true );
            if ( $id > 0 ) $this->customer_counts[ $id ][] = $user;
        }

        $filtered = [];
        foreach ( $users as $user ) {
            $data = $this->user_data( $user, $this->customer_counts );
            if ( ! $this->matches( $data, $filters ) ) continue;
            $filtered[] = $user;
        }

        $sort = $filters['sort'];
        usort( $filtered, function ( WP_User $first, WP_User $second ) use ( $sort ): int {
            $first_data = $this->user_data( $first, $this->customer_counts );
            $second_data = $this->user_data( $second, $this->customer_counts );

            if ( $sort === 'created' ) {
                $comparison = strcmp( $first->user_registered, $second->user_registered );
                return $comparison !== 0 ? -$comparison : $first->ID <=> $second->ID;
            }

            if ( $sort === 'last_login' ) {
                $first_login = (int) get_user_meta( $first->ID, 'wc_last_active', true );
                $second_login = (int) get_user_meta( $second->ID, 'wc_last_active', true );
                return $second_login <=> $first_login ?: $first->ID <=> $second->ID;
            }

            $first_value = $sort === 'user' ? $first_data['name'] : $first_data[ $sort ];
            $second_value = $sort === 'user' ? $second_data['name'] : $second_data[ $sort ];
            $comparison = strnatcasecmp( (string) $first_value, (string) $second_value );
            return $comparison !== 0 ? $comparison : $first->ID <=> $second->ID;
        } );

        return $filtered;
    }

    private function user_data( WP_User $user, array $customer_counts = [] ): array {
        $ttx_id = (int) get_user_meta( $user->ID, LavendelHygiene_Core::META_TRIPLETEX_ID, true );
        $status = (string) get_user_meta( $user->ID, LavendelHygiene_Core::META_STATUS, true );
        if ( $status === '' && in_array( LavendelHygiene_Core::PENDING_ROLE, (array) $user->roles, true ) ) $status = 'pending';
        if ( $status === '' ) $status = '—';
        $company_users = $ttx_id > 0 ? ( $customer_counts[ $ttx_id ] ?? $this->users_for_customer( $ttx_id ) ) : [];
        $company_users = array_values( array_filter( $company_users, [ $this, 'is_customer_relationship_user' ] ) );
        $approved = $pending = 0;
        foreach ( $company_users as $company_user ) {
            $company_status = (string) get_user_meta( $company_user->ID, LavendelHygiene_Core::META_STATUS, true );
            if ( $company_status === 'approved' || in_array( 'customer', (array) $company_user->roles, true ) ) $approved++;
            elseif ( $company_status === 'pending' || in_array( LavendelHygiene_Core::PENDING_ROLE, (array) $company_user->roles, true ) ) $pending++;
        }
        $discounts = $ttx_id > 0 ? LH_Ttx_Service_Registry::instance()->discounts()->get_cached_discount_map_for_user( (int) $user->ID ) : null;
        return [
            'name' => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
            'company' => (string) get_user_meta( $user->ID, 'billing_company', true ),
            'orgnr' => (string) get_user_meta( $user->ID, LavendelHygiene_Core::META_ORGNR, true ),
            'status' => $status,
            'roles' => array_map( 'translate_user_role', (array) $user->roles ),
            'role_slugs' => (array) $user->roles,
            'ttx_id' => $ttx_id,
            'contact_id' => (int) get_user_meta( $user->ID, '_tripletex_contact_id', true ),
            'delivery_id' => (int) get_user_meta( $user->ID, '_tripletex_delivery_address_id', true ),
            'user_type' => get_user_meta( $user->ID, LavendelHygiene_Core::META_IS_AVDELING, true ) === '1' ? 'avdeling' : 'standard',
            'user_type_label' => get_user_meta( $user->ID, LavendelHygiene_Core::META_IS_AVDELING, true ) === '1' ? __( 'Avdeling', 'lavendelhygiene' ) : __( 'Standard user', 'lavendelhygiene' ),
            'relationship' => count( $company_users ) > 1 ? 'multi' : ( $ttx_id > 0 ? 'single' : 'unlinked' ),
            'relationship_label' => $ttx_id <= 0 ? __( 'Unlinked', 'lavendelhygiene' ) : sprintf( __( '%s linked user(s): %d approved%s', 'lavendelhygiene' ), count( $company_users ) > 1 ? 'Multiple' : 'Single', $approved, $pending > 1 ? sprintf( __( ', %d pending', 'lavendelhygiene' ), $pending ) : '' ),
            'discount_count' => is_array( $discounts ) ? count( $discounts ) : 0,
            'created' => $user->user_registered ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $user->user_registered ) ) : '',
            'last_login' => $this->last_login( $user->ID ),
            'approved' => $status,
        ];
    }

    private function matches( array $data, array $filters ): bool {
        $haystack = strtolower( implode( ' ', [ $data['name'], $data['company'], $data['orgnr'], $data['roles'] ? implode( ' ', $data['roles'] ) : '', $data['ttx_id'], $data['contact_id'], $data['delivery_id'] ] ) );
        if ( $filters['search'] !== '' && strpos( $haystack, strtolower( $filters['search'] ) ) === false ) return false;
        if ( $filters['status'] !== 'all' && ( $filters['status'] === 'missing' ? $data['approved'] !== '—' : $data['approved'] !== $filters['status'] ) ) return false;
        if ( $filters['role'] !== 'all' && ! in_array( $filters['role'], $data['role_slugs'], true ) ) return false;
        if ( $filters['avdeling'] && $data['user_type'] !== 'avdeling' ) return false;
        if ( $filters['multiple_linked'] && $data['relationship'] !== 'multi' ) return false;
        if ( $filters['missing_tripletex'] && $data['ttx_id'] > 0 ) return false;
        if ( $filters['missing_contact'] && $data['contact_id'] > 0 ) return false;
        if ( $filters['missing_delivery'] && $data['delivery_id'] > 0 ) return false;
        return true;
    }

    private function get_filters(): array {
        $allowed = [ 'sort' => [ 'user', 'company', 'orgnr', 'last_login', 'created' ], 'status' => [ 'all', 'approved', 'pending', 'denied', 'missing' ], 'role' => array_merge( [ 'all' ], array_keys( $this->get_role_options() ) ) ];
        $filters = [ 'search' => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) ];
        foreach ( $allowed as $key => $values ) { $default = $key === 'sort' ? 'created' : 'all'; $value = sanitize_key( wp_unslash( $_GET[ $key ] ?? $default ) ); $filters[ $key ] = in_array( $value, $values, true ) ? $value : $default; }
        foreach ( [ 'avdeling', 'multiple_linked', 'missing_tripletex', 'missing_contact', 'missing_delivery' ] as $key ) $filters[ $key ] = ! empty( $_GET[ $key ] );
        return $filters;
    }

    private function get_role_options(): array {
        return array_intersect_key( wp_roles()->role_names, array_flip( [ 'customer', 'administrator', 'shop_manager', LavendelHygiene_Core::PENDING_ROLE, 'subscriber' ] ) );
    }

    private function option( string $name, string $selected, string $value, string $label ): void { printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $selected, $value, false ), esc_html( $label ) ); }
    private function pagination_args( array $filters ): array {
        $search = (string) ( $filters['search'] ?? '' );
        unset( $filters['search'] );
        foreach ( [ 'avdeling', 'multiple_linked', 'missing_tripletex', 'missing_contact', 'missing_delivery' ] as $key ) {
            if ( ! empty( $filters[ $key ] ) ) $filters[ $key ] = '1';
            else unset( $filters[ $key ] );
        }
        return array_merge( [ 's' => $search ], $filters );
    }
    private function users_for_customer( int $customer_id ): array { return get_users( [ 'meta_key' => LavendelHygiene_Core::META_TRIPLETEX_ID, 'meta_value' => $customer_id, 'number' => -1 ] ); }
    private function is_customer_relationship_user( WP_User $user ): bool {
        $status = (string) get_user_meta( $user->ID, LavendelHygiene_Core::META_STATUS, true );
        return $status === 'approved' || $status === 'pending' || in_array( 'customer', (array) $user->roles, true ) || in_array( LavendelHygiene_Core::PENDING_ROLE, (array) $user->roles, true );
    }
    private function last_login( int $user_id ): string {
        $value = get_user_meta( $user_id, 'wc_last_active', true );
        if ( ! $value ) return '';
        return is_numeric( $value )
            ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $value )
            : (string) $value;
    }
    private function assert_access(): void { if ( ! current_user_can( 'list_users' ) && ! current_user_can( 'promote_users' ) ) wp_die( __( 'You do not have permission.', 'lavendelhygiene' ) ); }
}