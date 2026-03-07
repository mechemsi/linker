<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;

class LinksControllerCest
{
    public function linksEndpointReturns200WithLinks(FunctionalTester $I): void
    {
        $I->sendGet('/links');

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'status' => 'ok',
        ]);
        $I->seeResponseJsonMatchesJsonPath('$.links');
        $I->seeResponseJsonMatchesJsonPath('$.links.server-alert');
        $I->seeResponseJsonMatchesJsonPath('$.links.server-alert.parameters');
        $I->seeResponseJsonMatchesJsonPath('$.links.server-alert.channels');
        $I->seeResponseJsonMatchesJsonPath('$.links.server-alert.message_template');
    }
}
