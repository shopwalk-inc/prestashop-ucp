<?php
/**
 * UCP Destination  ↔  PrestaShop Address mapping.
 *
 * UCP uses schema.org naming (street_address, address_locality, address_region,
 * postal_code, address_country). See UCP_SPEC_COMPLIANCE.md §4.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpAddress
{
    public static function fromPsAddress(Address $a): array
    {
        $street = trim((string) $a->address1);
        if (!empty($a->address2)) {
            $street .= "\n" . trim((string) $a->address2);
        }
        $country = new Country((int) $a->id_country);
        $state   = (int) $a->id_state > 0 ? new State((int) $a->id_state) : null;

        return [
            'id'               => 'dest_' . (int) $a->id,
            'street_address'   => $street,
            'address_locality' => (string) $a->city,
            'address_region'   => $state ? (string) $state->iso_code : '',
            'postal_code'      => (string) $a->postcode,
            'address_country'  => (string) $country->iso_code,
        ];
    }

    /**
     * Build a PrestaShop Address from a UCP Destination for an existing
     * customer. Persists and returns the Address.
     */
    public static function toPsAddress(array $dest, int $idCustomer, string $firstName = '', string $lastName = ''): Address
    {
        $address = new Address();
        $address->id_customer = $idCustomer;
        $address->firstname   = $firstName ?: 'Guest';
        $address->lastname    = $lastName  ?: 'Guest';
        $address->alias       = 'UCP';

        $street = (string) ($dest['street_address'] ?? '');
        $parts  = preg_split("/\r\n|\n|\r/", $street, 2);
        $address->address1 = (string) ($parts[0] ?? '');
        $address->address2 = isset($parts[1]) ? (string) $parts[1] : '';

        $address->city     = (string) ($dest['address_locality'] ?? '');
        $address->postcode = (string) ($dest['postal_code'] ?? '');

        $countryIso = strtoupper((string) ($dest['address_country'] ?? 'US'));
        $idCountry  = (int) Country::getByIso($countryIso);
        $address->id_country = $idCountry ?: (int) Configuration::get('PS_COUNTRY_DEFAULT');

        $regionIso = strtoupper((string) ($dest['address_region'] ?? ''));
        if ($regionIso && $address->id_country) {
            $idState = (int) State::getIdByIso($regionIso, $address->id_country);
            if ($idState) {
                $address->id_state = $idState;
            }
        }

        $address->save();
        return $address;
    }
}
