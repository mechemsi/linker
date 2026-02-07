<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTester;

class NotifyControllerCest
{
    public function validRequestReturns200(FunctionalTester $I): void
    {
        $I->sendGet('/notify/server-alert', [
            'server' => 'web1',
            'status' => 'down',
            'message' => 'disk full',
        ]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'status' => 'ok',
            'link' => 'server-alert',
        ]);
        $I->seeResponseJsonMatchesJsonPath('$.channels_notified');
    }

    public function unknownLinkReturns404(FunctionalTester $I): void
    {
        $I->sendGet('/notify/nonexistent-link');

        $I->seeResponseCodeIs(404);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'status' => 'error',
        ]);
    }

    public function missingRequiredParamsReturns400(FunctionalTester $I): void
    {
        $I->sendGet('/notify/server-alert');

        $I->seeResponseCodeIs(400);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'status' => 'error',
        ]);
        $I->seeResponseJsonMatchesJsonPath('$.errors');
    }

    public function optionalParamsUseDefaults(FunctionalTester $I): void
    {
        $I->sendGet('/notify/server-alert', [
            'server' => 'web1',
            'status' => 'down',
        ]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'status' => 'ok',
            'link' => 'server-alert',
        ]);
    }

    public function postMethodIsSupported(FunctionalTester $I): void
    {
        $I->sendPost('/notify/server-alert?server=web1&status=up');

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'status' => 'ok',
        ]);
    }

    public function slackWebhookLinkReturns200(FunctionalTester $I): void
    {
        $I->sendGet('/notify/test-slack', [
            'message' => 'Hello from test',
        ]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson([
            'status' => 'ok',
            'link' => 'test-slack',
        ]);
    }
}
