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
use yii\web\JqueryAsset;
use yii\web\AssetBundle;
use yii\web\Response;

class View extends \yii\web\View
{
    public static $firstRender = false;

    public $json = [];
    public $layoutVar = 'layout';
    public $contentId = 'content';
    public $linkSelector = 'a.fjax';
    public $jsCache = [];
    public $cssCache = [];
    public $clientOptions = [];

//    public function init()
//    {
//        parent::init();
//    }
    public function render($view, $params = [], $context = null)
    {
        $firstRender = false;
        if (self::$firstRender === false) {
            self::$firstRender = $firstRender = true;
        }

        $viewFile = $this->findViewFile($view, $context);
        $output = $this->renderFile($viewFile, $params, $context);

        if ($this->isAjax() && $firstRender) {

            $layout = Yii::$app->getRequest()->getHeaders()->get($this->layoutVar);
            echo $context->layout;
            if($layout != Yii::$app->layout){
                $this->json['redirect'] = Yii::$app->request->getAbsoluteUrl();
                return $this->output();
            }

            ob_start();
            ob_implicit_flush(false);

            $this->beginPage();
            $this->head();
            $this->beginBody();
            echo $output;
            $this->endBody();
            $this->endPage(true);

            $this->json['content']  =  ob_get_clean();
            $this->json['title']    = $this->title;

            $this->renderAjaxScripts();
            $this->renderAjaxCss();
            return $this->output();

        } elseif ($firstRender) {
            list($rootAssets,$webAssets) = Yii::$app->assetManager->publish('@vendor/pavlinter/yii2-fullajax/fullajax/assets/');

            $clientOptions = ArrayHelper::merge([
                'layout' => Yii::$app->layout,
                'linkSelector' => $this->linkSelector,
                'contentId' => $this->contentId,
                'jsCache' => $this->jsCache,
                'cssCache' => $this->cssCache,
            ],$this->clientOptions);

            $this->registerJsFile($webAssets.'/js/jquery.fjax.js',[JqueryAsset::className()]);
            $this->registerJs("jQuery.fjax(".Json::encode($clientOptions).");");
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
    public function renderAjaxScripts()
    {
        $headers = Yii::$app->getRequest()->getHeaders();
        $js = $headers->get('js');
        $jsList = [];
        if($js){
            $jsList = explode(',',$js);
        }

        $init = '';
        $ready = '';
        $this->json['scripts'] = [];
        if($this->jsFiles){
            foreach ($this->jsFiles as $pos => $scripts) {
                foreach ($scripts as $path => $val) {
                    if(!in_array($val,$jsList)){
                        $this->json['scripts']['links'][] = $val;
                    }

                }
            }
        }
        if($this->js){
            foreach ($this->js as $pos=>$scripts) {
                if($pos===self::POS_READY){
                    $ready  .= implode("", $this->js[self::POS_READY]);
                }else{
                    $init .= implode("", $this->js[self::POS_END]);
                }
            }
        }
        if($init)
            $this->json['scripts']['init'] = "function(){".$init."}";
        if($ready)
            $this->json['scripts']['ready'] = "function(){".$ready."}";

        if(!$this->json['scripts'])
            unset($this->json['scripts']);

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
        if(!$this->json['css'])
            unset($this->json['css']);

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
}
