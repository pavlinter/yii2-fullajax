<?php

/**
 * @copyright Copyright &copy; Pavels Radajevs, 2014
 * @package yii2-fullajax
 * @version 1.0.0
 */

namespace pavlinter\fullajax;

use Yii;
use yii\helpers\Json;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\JqueryAsset;
use yii\web\AssetBundle;
use yii\web\JsExpression;
use yii\web\Response;

class View extends \yii\web\View
{
    /**
     * @event Event an event that is triggered by [[beginBody()]].
     */
    const EVENT_PRE_AJAX_OUTPUT = 'preAjaxOutput';

    public static $firstRender = false;

    public $json = [];
    public $layoutVar = 'layout';
    public $contentId = 'content';
    public $linkSelector = 'a.fjax';
    public $jsCache = [];
    public $cssCache = [];
    public $clientOptions = [];
    public $clientEvents = [];
    public $jsonCallback;

    public function init()
    {
        parent::init();
        if (!$this->hasEventHandlers(self::EVENT_PRE_AJAX_OUTPUT)) {
            $this->on(self::EVENT_PRE_AJAX_OUTPUT, function ($event){
                if (isset($event->sender->params['breadcrumbs'])) {
                    $event->sender->json['breadcrumbs'] = \yii\widgets\Breadcrumbs::widget([
                        'links' => $event->sender->params['breadcrumbs'],
                    ]);
                }
            });
        }
    }


    public function render($view, $params = [], $context = null)
    {
        $firstRender = false;
        if (self::$firstRender === false) {
            self::$firstRender = $firstRender = true;
        }

        $viewFile = $this->findViewFile($view, $context);
        $output = $this->renderFile($viewFile, $params, $context);

        if ($this->isAjax() && $firstRender) {
            $layout = $context->layout === null?Yii::$app->layout:$context->layout;
            if($layout != Yii::$app->getRequest()->getHeaders()->get($this->layoutVar)){
                $this->json['redirect'] = Yii::$app->request->getAbsoluteUrl();
                $this->trigger(self::EVENT_PRE_AJAX_OUTPUT);
                return $this->output();
            }

            ob_start();
            ob_implicit_flush(false);
            $this->beginPage();
            $this->head();
            $this->beginBody();
            echo $output;
            $this->endBody();
            $this->endPage();

            $this->json['content']  =  ob_get_clean();
            $this->json['title']    = $this->title;

            $this->renderAjaxScripts();
            $this->renderAjaxCss();
            $this->trigger(self::EVENT_PRE_AJAX_OUTPUT);
            return $this->output();

        } elseif ($firstRender) {
            list($rootAssets,$webAssets) = Yii::$app->assetManager->publish('@vendor/pavlinter/yii2-fullajax/fullajax/assets/');

            $clientOptions = ArrayHelper::merge([
                'layout' => Yii::$app->layout,
                'linkSelector' => $this->linkSelector,
                'contentId' => $this->contentId,
                'jsCache' => $this->jsCache,
                'cssCache' => $this->cssCache,
                'eventsList' => [],
                'currentUrl' => Url::to(),
            ],$this->clientOptions);

            $this->registerJsFile($webAssets.'/js/jquery.fjax.js',[JqueryAsset::className()]);
            $script = '';
            foreach ($this->clientEvents as $event => $handler) {
                $script .= '$(document).on("' . $event . '" ,' . new JsExpression($handler) . ');';
                $clientOptions['eventsList'][$event] = 1;
            }
            $script .= "jQuery.fjax(" . Json::encode($clientOptions) . ");";

            $this->registerJs($script);
        }
        return $output;
    }
    public function output()
    {
        $response = Yii::$app->getResponse();
        $response->clearOutputBuffers();
        $response->setStatusCode(200);
        $response->format = Response::FORMAT_JSON;
        $response->data = $this->json;
        $response->send();
        Yii::$app->end();
    }

    public function renderAjaxCss()
    {
        $this->json['css'] = [];
        if (!empty($this->cssFiles)) {
            foreach ($this->cssFiles as $path) {
                $this->json['css']['links'][] = $path;
            }
        }
        if (!empty($this->css)) {
                $this->json['css']['code'] = implode("",$this->css);
        }
        if(!$this->json['css']) {
            unset($this->json['css']);
        }
    }
    public function registerCss($css, $options = [], $key = null)
    {
        $key = $key ?: md5($css);
        if($this->isAjax()){
            $this->css[$key] = $css;
        }else{
            $this->css[$key] = Html::style($css, $options);
        }

    }
    public function registerCssFile($url, $depends = [], $options = [], $key = null)
    {
        $url = Yii::getAlias($url);
        $key = $key ?: $url;
        $this->cssCache[$url] = true;
        if (empty($depends)) {
            if($this->isAjax()) {
                $this->cssFiles[$key] = $url;
            } else {
                $this->cssFiles[$key] = Html::cssFile($url, $options);
            }
        } else {
            $am = Yii::$app->getAssetManager();
            $am->bundles[$key] = new AssetBundle([
                'css' => [$url],
                'cssOptions' => $options,
                'depends' => (array)$depends,
            ]);
            $this->registerAssetBundle($key);
        }
    }
    public function renderAjaxScripts()
    {
        $init = '';
        $ready = '';
        $afterClose = '';
        if (!empty($this->jsFiles)) {
            foreach ($this->jsFiles as $pos => $scripts) {
                foreach ($scripts as $path => $val) {
                    if(!in_array($val,$this->jsCache)){
                        $this->json['scripts']['links'][] = $val;
                    }

                }
            }
        }
        if (!empty($this->js)) {


            if (isset($this->js[self::POS_READY]['fjax.afterClose'])) {
                $afterClose .= $this->js[self::POS_READY]['fjax.afterClose'];
                unset($this->js[self::POS_READY]['fjax.afterClose']);
            }

            foreach ($this->js as $pos => $scripts) {
                if($pos===self::POS_READY){
                    $ready  .= implode("", $this->js[self::POS_READY]);
                }else{
                    $init .= implode("", $this->js[self::POS_END]);
                }
            }
        }
        if (!empty($init)) {
            $this->json['scripts']['init'] = $init;
        }
        if (!empty($ready)) {
            $this->json['scripts']['ready'] = $ready;
        }
        if (!empty($afterClose)) {
            $this->json['scripts']['afterClose'] = $afterClose;
        }
    }
    public function registerJs($js, $position = self::POS_READY, $key = null)
    {
        if ($key === 'fjax.afterClose' && !$this->isAjax()) {
            return true;
        }
        $key = $key ?: md5($js);
        $this->js[$position][$key] = $js;
        if ($position === self::POS_READY || $position === self::POS_LOAD) {
            JqueryAsset::register($this);
        }
    }
    public function registerJsFile($url, $depends = [], $options = [], $key = null)
    {

        $url = Yii::getAlias($url);
        $key = $key ?: $url;
        $this->jsCache[$url] = true;
        if (empty($depends)) {
            $position = isset($options['position']) ? $options['position'] : self::POS_END;
            unset($options['position']);
            if($this->isAjax()){
                $this->jsFiles[$position][$key] = $url;
            } else {
                $this->jsFiles[$position][$key] = Html::jsFile($url, $options);
            }
        } else {
            $am = Yii::$app->getAssetManager();
            if (strpos($url, '/') !== 0 && strpos($url, '://') === false) {
                $url = Yii::$app->getRequest()->getBaseUrl() . '/' . $url;
            }
            $am->bundles[$key] = new AssetBundle([
                'js' => [$url],
                'jsOptions' => $options,
                'depends' => (array)$depends,
            ]);
            $this->registerAssetBundle($key);
        }
    }
    public function isAjax()
    {
        return Yii::$app->getRequest()->getHeaders()->get('X-Fjax');;
    }
    public function ajaxCached()
    {
        return $this->json['cache'] = 1;
    }
    /**
     * Marks the position of an HTML head section.
     */
    public function head()
    {
        if (!$this->isAjax()) {
            echo self::PH_HEAD;
        }
    }
    /**
     * Marks the beginning of an HTML body section.
     */
    public function beginBody()
    {
        if (!$this->isAjax()) {
            echo self::PH_BODY_BEGIN;
        }
        $this->trigger(self::EVENT_BEGIN_BODY);
    }
    /**
     * Marks the ending of an HTML body section.
     */
    public function endBody()
    {
        $this->trigger(self::EVENT_END_BODY);
        if (!$this->isAjax()) {
            echo self::PH_BODY_END;
        }
        foreach (array_keys($this->assetBundles) as $bundle) {
            $this->registerAssetFiles($bundle);
        }
    }
    /**
     * Marks the ending of an HTML page.
     * @param boolean $ajaxMode whether the view is rendering in AJAX mode.
     * If true, the JS scripts registered at [[POS_READY]] and [[POS_LOAD]] positions
     * will be rendered at the end of the view like normal scripts.
     */
    public function endPage($ajaxMode = false)
    {
        $this->trigger(self::EVENT_END_PAGE);
        if (!$this->isAjax()) {
            $content = ob_get_clean();

            echo strtr($content, [
                self::PH_HEAD => $this->renderHeadHtml(),
                self::PH_BODY_BEGIN => $this->renderBodyBeginHtml(),
                self::PH_BODY_END => $this->renderBodyEndHtml($ajaxMode),
            ]);

            $this->clear();
        }
    }
}
