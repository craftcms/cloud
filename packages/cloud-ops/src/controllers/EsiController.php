<?php

namespace craft\cloud\ops\controllers;

use Craft;
use craft\cloud\ops\Module;
use yii\web\Response;

class EsiController extends \craft\web\Controller
{
    protected array|bool|int $allowAnonymous = true;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        return Module::instance()
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
