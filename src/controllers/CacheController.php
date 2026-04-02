<?php

namespace samuelreichor\coPilotLangdock\controllers;

use craft\web\Controller;
use samuelreichor\coPilotLangdock\providers\LangdockConfig;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

class CacheController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    /**
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function actionInvalidateModels(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        LangdockConfig::invalidateModelCache();

        return $this->asJson(['success' => true]);
    }
}
