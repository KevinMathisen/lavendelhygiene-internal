<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------- Registration, Approval, Gating, Notifications ---------- */

class LavendelHygiene_Registration {
    public function __construct() {
        add_action( 'woocommerce_register_form', [ $this, 'register_fields' ] );
        add_filter( 'woocommerce_registration_errors', [ $this, 'validate_register_fields' ], 10, 3 );
        add_action( 'woocommerce_created_customer', [ $this, 'save_register_fields' ], 10, 3 );
        add_action( 'user_register', [ $this, 'set_pending_role' ], 20 );

        add_filter( 'woocommerce_new_customer_username', [ $this, 'filter_username_person' ], 10, 3 );

        // Redirect wordpress registration to woocommerce my account
        add_action( 'login_form_register', [ $this, 'redirect_wp_registration' ] );
        add_filter( 'register_url', [ $this, 'filter_register_url' ] );
    }

     /**
     * Renders extra registration fields (all labels in Norwegian) and styles the layout:
     * - E-post
     * - Kontaktperson fornavn + etternavn
     * - Telefon
     * - Firmanavn
     * - Organisasjonsnummer + Bransje/Næring
     * - Bruk EHF
     * - Fakturaadresse
     * - Leveringsadresse
     */
    public function register_fields() {
        if ( is_user_logged_in() ) return;

        // Sector options
        $sector_options = [
            'Datasenter'                   => 'Datasenter',
            'Laboratorier'                 => 'Laboratorier',
            'Sykehus'                      => 'Sykehus',
            'Sykehusapotek'                => 'Sykehusapotek',
            'Farmasøytisk'                 => 'Farmasøytisk',
            'Fiskeoppdrett'                => 'Fiskeoppdrett',
            'Bryggeri og drikkevarer'      => 'Bryggeri og drikkevarer',          
            'Meieri'                       => 'Meieri',
            'Bakeri'                       => 'Bakeri',
            'Annen Næringsmiddelindustri'  => 'Annen Næringsmiddelindustri',
            'Kjøkken'                      => 'Kjøkken',
            'Annet'                        => 'Annet',
        ];
        $country_options = [
            'NO' => 'NO',
            'SE' => 'SE',
            'DK' => 'DK',
            'FI' => 'FI',
            'GB' => 'GB',
        ];

        // Prefill posted values (after validation error)
        $posted = function( $key, $default = '' ) {
            return isset( $_POST[ $key ] ) ? esc_attr( wp_unslash( $_POST[ $key ] ) ) : $default;
        };

        $posted_sector    = $posted( 'company_sector' );
        $use_ehf_checked  = isset( $_POST['use_ehf'] ) ? (bool) $_POST['use_ehf'] : true; // default checked
        $is_avdeling_checked = isset( $_POST['lh_is_avdeling'] );
        $same_shipping_checked = isset( $_POST['shipping_same_as_billing'] )
            ? (bool) $_POST['shipping_same_as_billing']
            : true;

        $posted_contact_first   = $posted( 'contact_first_name' );
        $posted_contact_last    = $posted( 'contact_last_name' );
        ?>
        <style>
            /* Layout helpers */
            .lavendelhygiene-reg-grid { display: grid; grid-template-columns: 1fr; gap: 12px; }
            .lavendelhygiene-two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
            @media (max-width: 640px) { .lavendelhygiene-two { grid-template-columns: 1fr; } }
            .lavendelhygiene-checkbox { display:flex; align-items:center; gap:8px; }
            .lavendelhygiene-section-title { margin: 12px 0 4px; font-weight: 600; }
            /* Make default Woo fields full width */
            .woocommerce form.register .woocommerce-form-row { width: 100%; }
            /* Button spacing */
            .woocommerce form.register .woocommerce-Button { margin-top: 12px; }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function(){
                var form = document.querySelector('form.register');
                if(!form) return;
                var emailLabel = form.querySelector('label[for="reg_email"]');
                if(emailLabel && /email/i.test(emailLabel.textContent)) emailLabel.textContent = 'E-post *';
                var passLabel = form.querySelector('label[for="reg_password"]');
                if(passLabel && /password|passord/i.test(passLabel.textContent)) passLabel.textContent = 'Passord *';

                var same = document.getElementById('shipping_same_as_billing');
                var wrapper = document.getElementById('shipping_fields_wrapper');
                if (!same || !wrapper) return;

                var shippingIds = ['shipping_address_1','shipping_postcode','shipping_city','shipping_country'];

                function setShippingRequired(isRequired) {
                    shippingIds.forEach(function(id){
                        var el = document.getElementById(id);
                        if (!el) return;
                        if (isRequired) el.setAttribute('required', 'required');
                        else el.removeAttribute('required');
                    });
                }
                function updateShippingVisibility() {
                    if (same.checked) {
                        wrapper.style.display = 'none';
                        setShippingRequired(false);
                    } else {
                        wrapper.style.display = '';
                        setShippingRequired(true);
                    }
                }

                updateShippingVisibility();
                same.addEventListener('change', updateShippingVisibility);

                // Avdeling
                var isAvdeling = document.getElementById('lh_is_avdeling');
                var avdelingWrapper = document.getElementById('lh_avdeling_name_wrapper');
                var avdelingName = document.getElementById('lh_avdeling_name');

                function updateAvdelingVisibility() {
                    if (!isAvdeling || !avdelingWrapper || !avdelingName) return;

                    if (isAvdeling.checked) {
                        avdelingWrapper.style.display = '';
                        avdelingName.setAttribute('required', 'required');
                    } else {
                        avdelingWrapper.style.display = 'none';
                        avdelingName.removeAttribute('required');
                    }
                }

                updateAvdelingVisibility();

                if (isAvdeling) {
                    isAvdeling.addEventListener('change', updateAvdelingVisibility);
                }
            });
        </script>

        <div class="lavendelhygiene-reg-grid">
            <!-- Firmanavn 100% -->
            <p class="form-row form-row-wide">
                <label for="reg_company"><?php esc_html_e( 'Firmanavn', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                <input type="text" class="input-text" name="company_name" id="reg_company"
                    value="<?php echo $posted( 'company_name' ); ?>" required />
            </p>

            <!-- Orgnr 50% + Sector 50% -->
            <div class="lavendelhygiene-two">
                <p class="form-row">
                    <label for="reg_orgnr"><?php esc_html_e( 'Organisasjonsnummer', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="orgnr" id="reg_orgnr" placeholder="9 siffer"
                        value="<?php echo $posted( 'orgnr' ); ?>" required />
                </p>
                <p class="form-row">
                    <label for="company_sector"><?php esc_html_e( 'Bransje/Næring', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <select name="company_sector" id="company_sector" class="input-select" required>
                        <option value=""><?php esc_html_e( 'Velg…', 'lavendelhygiene' ); ?></option>
                        <?php foreach ( $sector_options as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $posted_sector, $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            </div>

            <!-- EHF checkbox (left-aligned) -->
            <p class="form-row lavendelhygiene-checkbox">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="use_ehf" value="1" <?php checked( $use_ehf_checked, true ); ?> />
                    <span><?php esc_html_e( 'Motta faktura på EHF', 'lavendelhygiene' ); ?></span>
                </label>
            </p>

            <!-- Avdeling checkbox -->
            <p class="form-row lavendelhygiene-checkbox">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="lh_is_avdeling" id="lh_is_avdeling" value="1" <?php checked( $is_avdeling_checked, true ); ?>
                    />
                    <span><?php esc_html_e( 'Dette er en avdeling under et allerede registrert firma', 'lavendelhygiene' ); ?></span>
                </label>
            </p>

            <p
                class="form-row form-row-wide"
                id="lh_avdeling_name_wrapper"
                <?php echo $is_avdeling_checked ? '' : 'style="display:none;"'; ?>
            >
                <label for="lh_avdeling_name">
                    <?php esc_html_e( 'Navn på avdeling', 'lavendelhygiene' ); ?>
                    <span class="required">*</span>
                </label>
                <input
                    type="text"
                    class="input-text"
                    name="lh_avdeling_name"
                    id="lh_avdeling_name"
                    value="<?php echo $posted( 'lh_avdeling_name' ); ?>"
                    <?php echo $is_avdeling_checked ? 'required' : ''; ?>
                />
            </p>

            <!-- Kontaktperson -->
            <div class="lavendelhygiene-section-title"><?php esc_html_e( 'Kontaktperson', 'lavendelhygiene' ); ?></div>
            <div class="lavendelhygiene-two">
                <p class="form-row">
                    <label for="contact_first_name"><?php esc_html_e( 'Fornavn', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="contact_first_name" id="contact_first_name"
                        value="<?php echo $posted_contact_first; ?>" required />
                </p>
                <p class="form-row">
                    <label for="contact_last_name"><?php esc_html_e( 'Etternavn', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="contact_last_name" id="contact_last_name"
                        value="<?php echo $posted_contact_last; ?>" required />
                </p>
            </div>

            <!-- Phone 100% -->
            <p class="form-row form-row-wide">
                <label for="reg_phone"><?php esc_html_e( 'Telefon', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                <input type="tel" class="input-text" name="phone" id="reg_phone"
                    value="<?php echo $posted( 'phone' ); ?>" required />
            </p>

            <!-- Fakturaadresse -->
            <div class="lavendelhygiene-section-title"><?php esc_html_e( 'Fakturadresse', 'lavendelhygiene' ); ?></div>
            <p class="form-row form-row-wide">
                <label for="billing_address_1"><?php esc_html_e( 'Adresse', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                <input type="text" class="input-text" name="billing_address_1" id="billing_address_1"
                    value="<?php echo $posted( 'billing_address_1' ); ?>" required />
            </p>
            <div class="lavendelhygiene-two">
                <p class="form-row">
                    <label for="billing_postcode"><?php esc_html_e( 'Postnummer', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="billing_postcode" id="billing_postcode"
                        value="<?php echo $posted( 'billing_postcode' ); ?>" required />
                </p>
                <p class="form-row">
                    <label for="billing_city"><?php esc_html_e( 'Poststed', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="billing_city" id="billing_city"
                        value="<?php echo $posted( 'billing_city' ); ?>" required />
                </p>
            </div>
            <p class="form-row">
                <label for="billing_country"><?php esc_html_e( 'Land', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                <select name="billing_country" id="billing_country" class="input-select" required>
                    <?php foreach ( $country_options as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $posted( 'billing_country', 'NO' ), $value ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <!-- samme fraktaddresse checkbox -->
            <p class="form-row lavendelhygiene-checkbox">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input
                        type="checkbox"
                        name="shipping_same_as_billing"
                        id="shipping_same_as_billing"
                        value="1"
                        <?php checked( $same_shipping_checked, true ); ?>
                    />
                    <span><?php esc_html_e( 'Leveringsadressen er samme som fakturaadressen', 'lavendelhygiene' ); ?></span>
                </label>
            </p>

            <!-- Leveringsadresse -->
            <div id="shipping_fields_wrapper" <?php echo $same_shipping_checked ? 'style="display:none;"' : ''; ?>>
                <div class="lavendelhygiene-section-title"><?php esc_html_e( 'Leveringsadresse', 'lavendelhygiene' ); ?></div>
                <p class="form-row form-row-wide">
                    <label for="shipping_address_1"><?php esc_html_e( 'Adresse', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="shipping_address_1" id="shipping_address_1"
                        value="<?php echo $posted( 'shipping_address_1' ); ?>" required />
                </p>
                <div class="lavendelhygiene-two">
                    <p class="form-row">
                        <label for="shipping_postcode"><?php esc_html_e( 'Postnummer', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                        <input type="text" class="input-text" name="shipping_postcode" id="shipping_postcode"
                            value="<?php echo $posted( 'shipping_postcode' ); ?>" required />
                    </p>
                    <p class="form-row">
                        <label for="shipping_city"><?php esc_html_e( 'Poststed', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                        <input type="text" class="input-text" name="shipping_city" id="shipping_city"
                            value="<?php echo $posted( 'shipping_city' ); ?>" required />
                    </p>
                </div>
                <p class="form-row">
                    <label for="shipping_country"><?php esc_html_e( 'Land', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <select name="shipping_country" id="shipping_country" class="input-select" required>
                        <?php foreach ( $country_options as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $posted( 'shipping_country', 'NO' ), $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            </div>
        </div>
        <?php
    }

    public function validate_register_fields( $errors, $username, $email ) {
        $same_shipping = isset( $_POST['shipping_same_as_billing'] );

        $required = [
            'company_name'       => __( 'Firmanavn', 'lavendelhygiene' ),
            'contact_first_name'  => __( 'Kontaktperson fornavn', 'lavendelhygiene' ),
            'contact_last_name'   => __( 'Kontaktperson etternavn', 'lavendelhygiene' ),
            'company_sector'     => __( 'Bransje/Næring', 'lavendelhygiene' ),
            'orgnr'              => __( 'Organisasjonsnummer', 'lavendelhygiene' ),
            'phone'              => __( 'Telefon', 'lavendelhygiene' ),
            'billing_address_1'  => __( 'Fakturadresse', 'lavendelhygiene' ),
            'billing_postcode'   => __( 'Faktura postnummer', 'lavendelhygiene' ),
            'billing_city'       => __( 'Faktura poststed', 'lavendelhygiene' ),
            'billing_country'    => __( 'Faktura land', 'lavendelhygiene' ),
        ];
        if ( ! $same_shipping ) {
            $required += [
                'shipping_address_1' => __( 'Leveringsadresse', 'lavendelhygiene' ),
                'shipping_postcode'  => __( 'Levering postnummer', 'lavendelhygiene' ),
                'shipping_city'      => __( 'Levering poststed', 'lavendelhygiene' ),
                'shipping_country'   => __( 'Levering land', 'lavendelhygiene' ),
            ];
        }
        if ( ! empty( $_POST['lh_is_avdeling'] ) ) {
            $required += [
                'lh_avdeling_name' => __( 'Avdeling navn', 'lavendelhygiene' ),
            ];
        }

        foreach ( $required as $key => $label ) {
            if ( empty( $_POST[ $key ] ) ) {
                $errors->add(
                    'required_' . $key,
                    sprintf( __( 'Vennligst fyll inn: %s.', 'lavendelhygiene' ), esc_html( $label ) )
                );
            }
        }

        // orgnr: norwegian mod11 checksum
        if ( ! empty( $_POST['orgnr'] ) ) {
            $orgnr_raw    = (string) wp_unslash( $_POST['orgnr'] );
            $orgnr_digits = preg_replace( '/\D+/', '', $orgnr_raw );

            if ( ! $this->is_valid_no_orgnr( $orgnr_digits ) ) {
                $errors->add( 'orgnr_invalid', __( 'Ugyldig organisasjonsnummer.', 'lavendelhygiene' ) );
            } else {
                // prevent duplicate org numbers
                $existing = get_users( [
                    'fields'     => 'ids',
                    'number'     => 1,
                    'meta_key'   => LavendelHygiene_Core::META_ORGNR,
                    'meta_value' => $orgnr_digits,
                ] );

                // TODO: just inform user that there are miltiple accounts connected to this orgnummer
            }
        }

        return $errors;
    }

    private function is_valid_no_orgnr( string $raw ): bool {
        $digits = preg_replace( '/\D+/', '', $raw );
        if ( strlen( $digits ) !== 9 ) return false;

        // Split digits
        $d = array_map( 'intval', str_split( $digits ) );
        // Weights for the first 8 digits
        $w = [3,2,7,6,5,4,3,2];

        $sum = 0;
        for ( $i = 0; $i < 8; $i++ ) {
            $sum += $d[$i] * $w[$i];
        }
        $rem = $sum % 11;
        $k   = 11 - $rem;

        if ( $k === 11 ) $k = 0;     // check digit 0 when remainder is 0
        if ( $k === 10 ) return false; // invalid number

        return $d[8] === $k;
    }

    public function save_register_fields( $customer_id ) {
        // Map + save basics
        $map = [
            'company_name'       => 'billing_company',
            'company_sector'     => LavendelHygiene_Core::META_SECTOR,
            'phone'              => 'billing_phone',

            'contact_first_name' => 'first_name',
            'contact_last_name'  => 'last_name',

            'billing_address_1'  => 'billing_address_1',
            'billing_postcode'   => 'billing_postcode',
            'billing_city'       => 'billing_city',
            'billing_country'    => 'billing_country',

            'shipping_address_1' => 'shipping_address_1',
            'shipping_postcode'  => 'shipping_postcode',
            'shipping_city'      => 'shipping_city',
            'shipping_country'   => 'shipping_country',
        ];
        foreach ( $map as $posted => $meta_key ) {
            if ( isset( $_POST[ $posted ] ) ) {
                update_user_meta( $customer_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $posted ] ) ) );
            }
        }

        if (isset($_POST['orgnr'])) {
            $orgnr = preg_replace('/\D+/', '', (string) wp_unslash($_POST['orgnr']));

            update_user_meta($customer_id, LavendelHygiene_Core::META_ORGNR, $orgnr);
        }

        $same_shipping = isset( $_POST['shipping_same_as_billing'] );
        if ( $same_shipping ) {
            $copy = [
                'billing_address_1' => 'shipping_address_1',
                'billing_postcode'  => 'shipping_postcode',
                'billing_city'      => 'shipping_city',
                'billing_country'   => 'shipping_country',
            ];
            foreach ( $copy as $billing_post_key => $shipping_meta_key ) {
                if ( isset( $_POST[ $billing_post_key ] ) ) {
                    update_user_meta($customer_id, $shipping_meta_key,
                        sanitize_text_field( wp_unslash( $_POST[ $billing_post_key ] ) ));
                }
            }
        }

        if ( isset( $_POST['company_name'] ) ) { // also set shipping company to company name
            update_user_meta($customer_id, 'shipping_company', sanitize_text_field( wp_unslash( $_POST['company_name'] ) ));
        }
        if ( isset( $_POST['phone'] ) ) { // also set shipping phone to phone
            update_user_meta($customer_id, 'shipping_phone', sanitize_text_field( wp_unslash( $_POST['phone'] ) ));
        }
        if ( isset( $_POST['contact_first_name'] ) ) {
            $first = sanitize_text_field( wp_unslash( $_POST['contact_first_name'] ) );
            update_user_meta( $customer_id, 'billing_first_name', $first );
            update_user_meta( $customer_id, 'shipping_first_name', $first );
        }
        if ( isset( $_POST['contact_last_name'] ) ) {
            $last = sanitize_text_field( wp_unslash( $_POST['contact_last_name'] ) );
            update_user_meta( $customer_id, 'billing_last_name', $last );
            update_user_meta( $customer_id, 'shipping_last_name', $last );
        }

        // EHF (checkbox)
        $use_ehf = isset( $_POST['use_ehf'] ) ? 'yes' : 'no';
        update_user_meta( $customer_id, LavendelHygiene_Core::META_USE_EHF, $use_ehf );

        // Avdeling metadata
        $is_avdeling = ! empty( $_POST['lh_is_avdeling'] );
        update_user_meta( $customer_id, LavendelHygiene_Core::META_IS_AVDELING, $is_avdeling ? '1' : '0' );

        $avdeling_name = '';
        if ( isset( $_POST['lh_avdeling_name'] ) ) {
            $avdeling_name = sanitize_text_field( wp_unslash( $_POST['lh_avdeling_name'] ) );
        }
        update_user_meta( $customer_id, LavendelHygiene_Core::META_AVDELING_NAME, $avdeling_name );

        // Set status to pending
        update_user_meta( $customer_id, LavendelHygiene_Core::META_STATUS, 'pending' );
    }

    public function set_pending_role( $user_id ) {
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) return;

        $roles               = (array) $user->roles;
        $is_woo_registration = isset( $_POST['woocommerce-register-nonce'] );

        if ( in_array( 'administrator', $roles, true ) ) return;

        if ( $is_woo_registration || in_array( 'customer', $roles, true ) ) {
            $user->set_role( LavendelHygiene_Core::PENDING_ROLE );
            update_user_meta( $user_id, LavendelHygiene_Core::META_STATUS, 'pending' );
        }
    }

    /**
     * Generate username from the contact person's name.
     * - Ola Nordmann        => ola.nordmann
     * - Anne Marie Hansen   => anne-marie.hansen
     * - Duplicate username  => ola.nordmann2.
     */
    public function filter_username_person( $generated_username, $email, $args ) {
        $first_name = isset($_POST['contact_first_name'])
            ? sanitize_text_field(wp_unslash($_POST['contact_first_name'])) : '';

        $last_name = isset($_POST['contact_last_name'])
            ? sanitize_text_field(wp_unslash($_POST['contact_last_name'])) : '';

        if ($first_name === '' || $last_name === '') return $generated_username;

        $first_name = $this->username_name_part($first_name);
        $last_name = $this->username_name_part($last_name);

        if ($first_name === '' || $last_name === '') return $generated_username;

        $base = sanitize_user($first_name . '.' . $last_name, true);

        if ($base === '') return $generated_username;

        return $this->unique_username($base);
    }

    /**
     * Normalize a persons name for username
     */
    private function username_name_part(string $value): string {
        $value = strtolower(remove_accents($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        return trim((string) $value, '-');
    }

    /**
     * Return the base username or append the smallest available number.
     */
    private function unique_username(string $base): string {
        if (!username_exists($base)) return $base;

        $suffix = 2;
        do {
            $candidate = $base . $suffix;
            $suffix++;
        } while (username_exists($candidate));

        return $candidate;
    }

    /**
     * Redirect native WordPress registration to WooCommerce My Account.
     */
    public function redirect_wp_registration() {
        if ( function_exists( 'wc_get_page_permalink' ) ) {
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }

        wp_safe_redirect( home_url( '/my-account/' ) );
        exit;
    }

    /**
     * Make WordPress-generated registration links point to WooCommerce.
     */
    public function filter_register_url( $url ) {
        if ( function_exists( 'wc_get_page_permalink' ) ) {
            return wc_get_page_permalink( 'myaccount' );
        }

        return home_url( '/my-account/' );
    }
}