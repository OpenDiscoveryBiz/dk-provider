<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use GuzzleHttp\Client;
use GuzzleHttp\json_decode;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Psr7\Uri;

class OpenDiscoveryController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    public function frontpage()
    {
        return redirect("https://github.com/OpenDiscoveryBiz/dk-provider");
    }

    public function lookup(Request $request, $id)
    {
        $pretty = $request->query('pretty');

        if (empty($id)) {
            return response()->json([
                'type' => 'official',
                'error' => 'missing_id',
            ], 400);
        }

        $id = Str::upper($id);
        $id = preg_replace("/[^A-Z0-9]+/", "", $id);

        $idMatches = [];
        if (!preg_match("/^(DK)([0-9]+)$/", $id, $idMatches)) {
            return response()->json([
                'type' => 'official',
                'error' => 'invalid_id',
            ], 400);
        }

        $country = $idMatches[1];
        $localId = $idMatches[2];

        try {
            $response = $this->doResolve($country, $localId);
        } catch (\Exception $e) {
            return response()->json([
                'type' => 'official',
                'error' => 'upstream_down',
                'error_detailed' => $e->getMessage(),
            ], 504);
        }

        if (empty($response)) {
            return response()->json([
                'type' => 'official',
                'error' => 'no_results',
            ], 404);
        }

        if (!empty($pretty)) {
            return response()->json($response, 200, [], JSON_PRETTY_PRINT);
        }

        return response()->json($response, 200);
    }

    public function doResolve($country, $localId)
    {
        $erst = $this->getErstData($localId);

        if (empty($erst)) {
            return [];
        }

        $company = [
            'type' => 'official',
            'id' => $country.$localId,
            'ttl' => (int) env('DK_TTL'),
            'voluntaryProviders' => [],
        ];

        $this->translateName($company, $erst);

        $this->translateHomepage($company, $erst);
        $this->translateDkStatusTimeline($company, $erst);
        $this->translateMainLineOfBusinessNaceV2($company, $erst);
        $this->translateDkEmployees($company, $erst);
        $this->translateDkManagement($company, $erst);
        $this->translateAddress($company, $erst);

        $this->rewriteHomepageToProvider($company);

        return $company;
    }

    protected function translateName(&$company, &$erst)
    {
        $company['name'] = $erst['virksomhedMetadata']['nyesteNavn']['navn'];
    }

    protected function translateHomepage(&$company, &$erst)
    {
        $erst_homepage = $this->getErstCurrent($erst['hjemmeside']);
        if (empty($erst_homepage)) {
            return false;
        }
        $company['homepage'] = $erst_homepage['kontaktoplysning'];
    }

    protected function translateDkStatusTimeline(&$company, &$erst)
    {
        static $translate = [
            'UDEN RETSVIRKNING' => ['Uden retsvirkning', 'Without legal force'],
            'NORMAL' => ['I normal drift', 'In normal operation'],
            'UNDER FRIVILLIG LIKVIDATION' => ['Under frivillig likvidation', 'In voluntary liquidation'],
            'UNDER REKONSTRUKTION' => ['Under rekonstruktion', 'Under reconstruction'],
            'UNDER KONKURS' => ['Under konkursbehandling', 'Filed for bankruptcy'],
            'UNDER TVANGSOPLØSNING' => ['Under tvangsopløsning', 'In compulsory dissolution'],
            'OPLØST EFTER KONKURS' => ['Opløst efter konkurs', 'Dissolved after bankruptcy'],
            'TVANGSOPLØST' => ['Tvangsopløst', 'Compulsorily dissolved'],
            'OPLØST EFTER FRIVILLIG LIKVIDATION' => ['Opløst efter frivillig likvidation', 'Dissolved after voluntary liquidation'],
            'OPLØST EFTER ERKLÆRING' => ['Opløst efter erklæring', 'Dissolved after statement'],
            'UNDER REASSUMERING' => ['Under reassumering', 'Under reassumption'],
            'SLETTET' => ['Slettet', 'Deleted'],
            'OPLØST EFTER FUSION' => ['Opløst efter fusion', 'Dissolved after merger'],
            'OPLØST EFTER SPALTNING' => ['Opløst efter spaltning', 'Dissolved after demerger'],
            'Aktiv' => ['Aktiv', 'Active'],
            'Ophørt' => ['Ophørt', 'Closed down'],
        ];

        $statusTimeline = [];
        $reachedNormal = false;
        foreach ($erst['virksomhedsstatus'] as $status) {
            if ($status['status'] === "NORMAL") {
                $reachedNormal = true;
            }

            // Skip all statuses until we reach established
            if (!$reachedNormal) {
                continue;
            }

            if (!array_key_exists($status['status'], $translate)) {
                throw new \InvalidArgumentException("Unknown status: ".$status['status']);
            }

            $translated = $translate[$status['status']];

            $statusTimeline[] = [
                'date' => $status['periode']['gyldigFra'],
                'value' => $translated[0],
                'translated' => $translated[1],
            ];
        }

        if (empty($status)) {
            $erst_livsforloeb = $this->getErstLast($erst['livsforloeb']);
            if (!empty($erst_livsforloeb)) {
                $translated = $translate['Aktiv'];
                $statusTimeline[] = [
                    'date' => $erst_livsforloeb['periode']['gyldigFra'],
                    'value' => $translated[0],
                    'translated' => $translated[1],
                ];

                if (!empty($erst_livsforloeb['periode']['gyldigTil'])) {
                    $translated = $translate['Ophørt'];
                    $statusTimeline[] = [
                        'date' => $erst_livsforloeb['periode']['gyldigTil'],
                        'value' => $translated[0],
                        'translated' => $translated[1],
                    ];
                }
            }
        }

        $company['dkStatusTimeline'] = $statusTimeline;
    }

    protected function translateMainLineOfBusinessNaceV2(&$company, &$erst)
    {
        $nyh = $erst['virksomhedMetadata']['nyesteHovedbranche'];

        $company['mainLineOfBusinessNaceV2'] = substr($nyh['branchekode'], 0, 4);
    }

    protected function translateDkEmployees(&$company, &$erst)
    {
        $nym = $erst['virksomhedMetadata']['nyesteMaanedsbeskaeftigelse'];
        if(empty($nym)) {
            return;
        }

        $interval_split = explode("_", $nym['intervalKodeAntalAnsatte']);

        $employees = [
            'date' => $nym['aar']."-".$nym['maaned'],
            'from' => (int) $interval_split[1],
            'to' => (int) $interval_split[2],
        ];

        $company['dkEmployees'] = $employees;
    }

    protected function translateDkManagement(&$company, &$erst)
    {
        $boardMembers = [];

        foreach ($erst['deltagerRelation'] as $deltager) {
            if ($deltager['deltager']['enhedstype'] !== "PERSON") {
                continue;
            }

            $id = $deltager['deltager']['enhedsNummer'];
            $name = $this->getErstCurrent($deltager['deltager']['navne'])['navn'];

            $role = null;
            foreach ($deltager['organisationer'] as $organisation) {
                if ($organisation['hovedtype'] !== "LEDELSESORGAN" &&
                    $organisation['hovedtype'] !== "FULDT_ANSVARLIG_DELTAGERE") {
                    continue;
                }
                foreach ($organisation['medlemsData'] as $medlemsData) {
                    foreach ($medlemsData['attributter'] as $attribut) {
                        if ($attribut['type'] !== 'FUNKTION') {
                            continue;
                        }

                        $role = $this->getErstCurrent($attribut['vaerdier'])['vaerdi'];
                        $role = Str::lower($role);
                        $role = Str::ucfirst($role);
                    }
                }
            }

            if (!empty($role)) {
                $boardMembers[] = [
                    'id' => $id,
                    'name' => $name,
                    'role' => $role,
                ];
            }
        }

        $company['dkManagement'] = $boardMembers;
    }

    protected function translateAddress(&$company, &$erst)
    {
        $nya = $erst['virksomhedMetadata']['nyesteBeliggenhedsadresse'];
        $addressLines = [];
        $addressLines[] = trim(preg_replace("/ +/", $nya['vejnavn']." ".$nya['husnummerFra']."".$nya['bogstavFra']." ".$nya['etage']." ".$nya['sidedoer'], " "));
        $addressLines[] = $nya['postnummer']." ".$nya['postdistrikt'];
        $addressLines[] = $nya['landekode'];
        if (!empty($addressLines)) {
            $company['addressLines'] = $addressLines;
        }
    }

    protected function rewriteHomepageToProvider(&$company)
    {
        if (empty($company['homepage'])) {
            return false;
        }

        $homepage = trim($company['homepage']);

        if (empty($homepage)) {
            return false;
        }

        if (strpos($homepage, " ") !== FALSE) {
            return false;
        }

        if (!preg_match("|^[a-z0-9]+?://|i", $homepage)) {
            $homepage = "http://".$homepage;
        }

        $uri = new Uri($homepage);

        if (empty($uri->getHost())) {
            return false;
        }

        if (in_array($uri->getHost(), ['localhost'])) {
            return false;
        }

        if (!in_array($uri->getScheme(), ['http', 'https'])) {
            $uri = $uri->withScheme('http');
        }

        $provider = $uri->getScheme()."://";
        $provider .= $uri->getHost();
        if ($uri->getPort() !== null) {
            $provider .= ':'.$uri->getPort();
        }

        $company['voluntaryProviders'][] = $provider;
    }

    protected function getErstData($localId)
    {
        $localId = (int) $localId;

        $cached = Cache::get('erstData_'.$localId);
        if ($cached !== NULL) {
            return $cached;
        }

        $client = new Client([
            'timeout' => 5,
            'connect_timeout' => 5,
            'read_timeout' => 5,
            'auth' => [env('ERST_CVR_USER'), env('ERST_CVR_PASS')],
        ]);

        $response = $client->request("POST", "http://distribution.virk.dk/cvr-permanent/virksomhed/_search", [
            'json' => [
                'from' => 0,
                'size' => 1,
                'query' => [
                    'bool' => [
                       'must' => [
                            [
                                'term' => [
                                    'Vrvirksomhed.cvrNummer' => $localId,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'allow_redirects' => false,
        ]);

        $jsonString = (string) $response->getBody();

        $json = json_decode($jsonString, true);

        if ($json['hits']['total'] === 0) {
            Cache::put('erstData_'.$localId, false, 60);

            return false;
        }

        if ($json['hits']['total'] !== 1) {
            throw new \Exception("ID matched more than one company");
        }

        $source = $json['hits']['hits'][0]['_source']['Vrvirksomhed'];

        if ($source['cvrNummer'] !== $localId) {
            throw new \Exception("ERST lookup did not return correct company");
        }

        Cache::put('erstData_'.$localId, $source, 60);

        return $source;
    }

    protected function getErstCurrent($array)
    {
        if (empty($array)) {
            return false;
        }

        if (!array_key_exists('periode', $array[0])) {
            throw new \InvalidArgumentException("The array needs items with the period param");
        }

        foreach ($array as $item) {
            if ($item['periode']['gyldigTil'] === NULL) {
                return $item;
            }
        }

        return false;
    }

    protected function getErstLast($array)
    {
        if (empty($array)) {
            return false;
        }

        if (!array_key_exists('periode', $array[0])) {
            throw new \InvalidArgumentException("The array needs items with the period param");
        }

        return end($array);
    }
}
