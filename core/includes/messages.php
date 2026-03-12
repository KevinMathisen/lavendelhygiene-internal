<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LavendelHygiene_Messages {
    const OPTION_KEY = 'lavendelhygiene_messages';

    public static function defaults(): array {
        return [
            'signup_notice' => '<h3>Velkommen til vår nye nettbutikk!</h3>Lavendel Hygiene har lansert en ny nettbutikk for bestilling på nett. Her kan du blant annet handle direkte, få ordrestatus og se ordrehistorikk.<br><br>For å se priser, lagre produkter og gjennomføre bestillinger må du ha en registrert bruker. Dette gjelder også for eksisterende kunder.<br><br>Du kan enkelt opprette konto via registreringsskjema nedenfor. Vennligst registrer korrekt organisasjonsnummer, samt e-postadresse og navn til innkjøpsansvarlig (e-postadressen brukes til å administere kontoen og for ordrebekreftelser). Etter registrering kan du ved behov legge til egen kontaktperson for levering under Min side.<br><br>Alle registreringer må godkjennes av oss før tilgang til priser og bestilling aktiveres. Du vil motta e-post så snart kontoen din er godkjent.',
            'cart_checkout_notice' => 'Hvis du har fast rabatt eller avtalepris reflekteres dette her.<br>Hvis du har fått et tilbud reflekteres det derimot ikke automatisk. Legg ved din tilbudsreferanse i kassen, så vil tilbudets priser reflekteres i endelig faktura.<br>Frakt tilkommer og kan variere etter varetype og leveringsmåte. Se <a href="{shipping_url}" target="_blank" rel="noopener noreferrer">Frakt og levering</a>.',
            'installation_product_notice' => 'Produktet krever individuell tilpasning og installasjon. Pris fastsettes basert på ønsket løsning og omfang, og selges derfor ikke direkte i nettbutikken.<br><a href="{contact_url}">Kontakt oss</a> for tilbud eller mer informasjon!',
            'catalog_only_notice' => 'Dette produktet selges ikke for tiden direkte i nettbutikken.<br><a href="{contact_url}">Kontakt oss</a> for tilbud eller mer informasjon!',
            'temporary_unavailable_notice' => 'Dette produktet er midlertidig ikke tilgjengelig for kjøp i nettbutikken.<br><a href="{contact_url}">Kontakt oss</a> for mer informasjon!',
            'volume_pricing_notice' => 'Nettsidepris gjelder ved standard bestilling. Ved større volum kan vi tilby avtalepris og tilpassede leveringsbetingelser, <a href="{contact_url}">kontakt oss</a> for tilbud.',
        ];
    }

    public static function all(): array {
        $saved = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }

        return wp_parse_args( $saved, self::defaults() );
    }

    public static function get( string $key ): string {
        $messages = self::all();
        return isset( $messages[ $key ] ) ? (string) $messages[ $key ] : '';
    }

    public static function sanitize_messages_input( array $input ): array {
        $defaults = self::defaults();
        $clean = [];

        foreach ( $defaults as $key => $default_value ) {
            $raw = isset( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : '';
            $clean[ $key ] = wp_kses_post( (string) $raw );
        }

        return $clean;
    }

    public static function render( string $key, array $replacements = [] ): string {
        $message = self::get( $key );

        foreach ( $replacements as $placeholder => $value ) {
            $message = str_replace( '{' . $placeholder . '}', (string) $value, $message );
        }

        return $message;
    }
}