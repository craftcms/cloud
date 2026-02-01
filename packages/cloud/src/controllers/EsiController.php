<?php

namespace craft\cloud\controllers;

use Craft;
use craft\cloud\Plugin;
use yii\web\Response;

class EsiController extends \craft\web\Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        return Plugin::getInstance()
            ->getUrlSigner()
            ->verify(Craft::$app->getRequest()->getAbsoluteUrl());
    }

    public function actionRenderTemplate(string $template, array $variables = []): Response
    {
        // No-cache headers are applied to all action requests by default. Remove them.
        // @see https://github.com/craftcms/cms/pull/16364
        Craft::$app->getResponse()->getHeaders()->remove('Expires');
        Craft::$app->getResponse()->getHeaders()->remove('Pragma');
        Craft::$app->getResponse()->getHeaders()->remove('Cache-Control');

        return $this->renderTemplate($template, $variables);
    }
}
