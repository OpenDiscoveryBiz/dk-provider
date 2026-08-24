<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_frontpage_redirects_to_github(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('https://github.com/OpenDiscoveryBiz/dk-provider');
    }

    public function test_lookup_rejects_invalid_dk_id(): void
    {
        $response = $this->get('/.well-known/opendiscovery/DKABC.json');

        $response->assertStatus(400)
            ->assertJson([
                'type' => 'official',
                'error' => 'invalid_id',
            ]);
    }

    public function test_lookup_returns_no_results_when_cvr_has_no_hits(): void
    {
        Http::fake([
            'distribution.virk.dk/*' => Http::response([
                'hits' => [
                    'total' => 0,
                    'hits' => [],
                ],
            ]),
        ]);

        $response = $this->get('/.well-known/opendiscovery/DK12345678.json');

        $response->assertStatus(404)
            ->assertJson([
                'type' => 'official',
                'error' => 'no_results',
            ]);
    }

    public function test_lookup_returns_company_from_cvr(): void
    {
        Http::fake([
            'distribution.virk.dk/*' => Http::response([
                'hits' => [
                    'total' => 1,
                    'hits' => [[
                        '_source' => [
                            'Vrvirksomhed' => [
                                'cvrNummer' => 12345678,
                                'virksomhedMetadata' => [
                                    'nyesteNavn' => ['navn' => 'Test ApS'],
                                    'nyesteHovedbranche' => ['branchekode' => '620100'],
                                    'nyesteErstMaanedsbeskaeftigelse' => null,
                                    'nyesteMaanedsbeskaeftigelse' => null,
                                    'nyesteBeliggenhedsadresse' => [
                                        'vejnavn' => 'Testvej',
                                        'husnummerFra' => '1',
                                        'bogstavFra' => '',
                                        'etage' => '',
                                        'sidedoer' => '',
                                        'postnummer' => '1000',
                                        'postdistrikt' => 'København K',
                                        'landekode' => 'DK',
                                    ],
                                ],
                                'hjemmeside' => [],
                                'virksomhedsstatus' => [[
                                    'status' => 'NORMAL',
                                    'periode' => ['gyldigFra' => '2020-01-01'],
                                ]],
                                'livsforloeb' => [],
                                'deltagerRelation' => [],
                            ],
                        ],
                    ]],
                ],
            ]),
        ]);

        $response = $this->get('/.well-known/opendiscovery/DK12345678.json');

        $response->assertOk()
            ->assertJsonPath('type', 'official')
            ->assertJsonPath('id', 'DK12345678')
            ->assertJsonPath('name', 'Test ApS');
    }
}
