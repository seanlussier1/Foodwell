<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Env;
use Foodwell\Http\Response;
use Foodwell\Repositories\DeliveryOptionRepository;
use Foodwell\Services\PostalAreaClient;
use Foodwell\Services\PostalLookupException;
use RuntimeException;

final class DeliveryOptionController
{
    public function __construct(private PostalAreaClient $postal, private DeliveryOptionRepository $slots) {}

    public function index(array $params = []): void
    {
        if (array_keys($_GET) !== ['postalCode'] || !is_string($_GET['postalCode'])) {
            Response::error(400, 'Supply only a postalCode query parameter.'); return;
        }
        $postalCode = strtoupper(trim($_GET['postalCode']));
        if (!preg_match('/^[ABCEGHJ-NPRSTVXY][0-9][ABCEGHJ-NPRSTV-Z] ?[0-9][ABCEGHJ-NPRSTV-Z][0-9]$/D', $postalCode)) {
            Response::error(400, 'Use a Canadian postal code such as H3N 1X1.', [], ['postalCode' => 'Expected A1A 1A1 or A1A1A1 format.']); return;
        }
        $compact = str_replace(' ', '', $postalCode); $prefix = substr($compact, 0, 3);
        $postalCode = $prefix . ' ' . substr($compact, 3);
        $servicePrefixes = array_values(array_unique(array_filter(array_map('trim', explode(',', strtoupper(Env::get('DELIVERY_AREA_PREFIXES', 'H3N,H3S,H3R')))))));
        if (count($servicePrefixes) > 100) { throw new RuntimeException('Delivery-area configuration is invalid.'); }
        foreach ($servicePrefixes as $configured) {
            if (!preg_match('/^[ABCEGHJ-NPRSTVXY][0-9][ABCEGHJ-NPRSTV-Z]$/D', $configured)) { throw new RuntimeException('Delivery-area configuration is invalid.'); }
        }
        $mode = Env::get('POSTAL_LOOKUP_MODE', 'live');
        $lookupSource = 'unavailable';
        $response = [
            'status' => 'complete', 'postalCode' => $postalCode,
            'area' => ['prefix' => $prefix, 'lookupStatus' => 'found', 'places' => [], 'source' => 'unavailable', 'fetchedAt' => null],
            'delivery' => ['eligible' => null, 'status' => 'unknown', 'slots' => [], 'bookingConfirmed' => false],
            'sources' => ['internal' => 'Foodwell configured delivery prefixes and database delivery slots/orders',
                'external' => 'Zippopotam.us / GeoNames', 'providerUrl' => 'https://www.zippopotam.us/',
                'dataUrl' => 'https://www.geonames.org/', 'licenseUrl' => 'https://www.geonames.org/export/'],
            'notice' => 'Postal-area lookup only; the full postal code, street address and deliverability are not verified. Availability may change before checkout.',
            'warnings' => [],
        ];
        try {
            if ($mode === 'live') { $area = $this->postal->lookup($prefix); $lookupSource = $area['source']; }
            else {
                if (Env::get('APP_POSTAL_DEMO', '0') !== '1' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
                    throw new PostalLookupException('configuration');
                }
                $lookupSource = 'demo';
                if ($mode === 'demo-timeout') { throw new PostalLookupException('timeout'); }
                if ($mode === 'demo-malformed') { throw new PostalLookupException('invalid-response'); }
                if ($mode === 'demo-not-found') { throw new PostalLookupException('not-found'); }
                if ($mode !== 'demo-success') { throw new PostalLookupException('configuration'); }
                $area = ['places' => [['name' => 'Demonstration area (fixture)', 'province' => 'Quebec', 'provinceCode' => 'QC']], 'fetchedAt' => null];
            }
            $response['area'] = array_merge($response['area'], ['places' => $area['places'], 'source' => $lookupSource, 'fetchedAt' => $area['fetchedAt']]);
            $eligible = in_array($prefix, $servicePrefixes, true);
            $slots = $eligible ? $this->slots->available() : [];
            $response['delivery'] = ['eligible' => $eligible, 'status' => !$eligible ? 'outside-area' : ($slots === [] ? 'no-slots' : 'available'), 'slots' => $slots, 'bookingConfirmed' => false];
        } catch (PostalLookupException $error) {
            $response['status'] = 'partial';
            $response['area']['lookupStatus'] = $error->reason === 'not-found' ? 'not-found' : 'unavailable';
            $response['area']['source'] = $lookupSource;
            $response['warnings'][] = ['code' => $error->reason, 'message' => 'Delivery availability could not be confirmed. Try again later; no booking was made.'];
        }
        if ($lookupSource === 'demo') { $response['warnings'][] = ['code' => 'demo-data', 'message' => 'Controlled local demonstration; this is not a live provider result.']; }
        header('Cache-Control: no-store');
        Response::json(200, $response);
    }
}
