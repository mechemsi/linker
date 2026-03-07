<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;

class HealthControllerCest
{
    public function healthEndpointReturns200(FunctionalTester $I): void
    {
        $I->sendGet('/health');

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'status' => 'ok',
        ]);
        $I->seeResponseJsonMatchesJsonPath('$.links_loaded');
    }
}
