<?php
/**
* Manage Cadastre request.
* @package   lizmap
* @subpackage cadastre
* @author    3liz
* @copyright 2015 3liz
* @link      http://3liz.com
* @license Mozilla Public License : http://www.mozilla.org/MPL/
*/

jClasses::inc('lizmap~lizmapProxy');
jClasses::inc('lizmap~lizmapOGCRequest');
class lizmapCadastreRequest extends lizmapOGCRequest {

    protected $tplExceptions = 'cadastre~cadastre_exception';

    protected function getcapabilities()
    {
        // Get cached session
        $key = session_id().'-'.
               $this->project->getRepository()->getKey().'-'.
               $this->project->getKey().'-'.
               $this->param('service').'-getcapabilities';
        if (jAuth::isConnected()) {
            $juser = jAuth::getUserSession();
            $key .= '-'.$juser->login;
        }
        $key = sha1($key);
        $cached = false;
        try {
            $cached = jCache::get($key, 'qgisprojects');
        } catch (Exception $e) {
            // if qgisprojects profile does not exist, or if there is an
            // other error about the cache, let's log it
            jLog::logEx($e, 'error');
        }
        // invalid cache
        if ($cached !== false && $cached['mtime'] < $this->project->getFileTime() ) {
            $cached = false;
        }
        // return cached data
        if ($cached !== false) {
            return (object) array(
                'code' => $cached['code'],
                'mime' => $cached['mime'],
                'data' => $cached['data'],
                'cached' => true,
            );
        }

        $querystring = $this->constructUrl();

        // Get remote data
        list($data, $mime, $code) = lizmapProxy::getRemoteData($querystring);

        // Retry if 500 error ( hackish, but QGIS Server segfault sometimes with cache issue )
        if ($code == 500) {
            // Get remote data
            list($data, $mime, $code) = lizmapProxy::getRemoteData($querystring);
        }

        if( $mime != 'text/json' && $mime != 'application/json' ){
            $code = 400;
            $mime = 'application/json';
            $data = json_encode((object) array(
                'status'=> 'fail',
                'message'=> 'Cadastre - Plugin non disponible',
            ));
        }

        $cached = array(
            'mtime' => $this->project->getFileTime(),
            'code' => $code,
            'mime' => $mime,
            'data' => $data,
        );
        $cached = jCache::set($key, $cached, 3600, 'qgisprojects');

        return (object) array(
            'code' => $code,
            'mime' => $mime,
            'data' => $data,
            'cached' => $cached,
        );
    }

    function createPdf(){

        // Access control
        if( !jAcl2::check("cadastre.acces.donnees.proprio") ){
            jMessage::add('Cadastre - Droits insuffisants pour accéder aux données de propriété', 'Error');
            return $this->serviceException();
        }

        $querystring = $this->constructUrl();
//jLog::log( $querystring );
        // Get remote data
        $getRemoteData = lizmapProxy::getRemoteData(
          $querystring,
          $this->services->proxyMethod,
          $this->services->debugMode
        );
        $data = $getRemoteData[0];
        $mime = $getRemoteData[1];
        $code = $getRemoteData[2];

        jMessage::clearAll();

        // Check mime type
        if( $mime != 'text/json' && $mime != 'application/json' ){
            jMessage::add('QGIS Server has not returned expected response', 'Error');
            return $this->serviceException();
        }

        // Check for expected errors
        $data = json_decode($data);
        if( $data->status == 'fail' ){
            jMessage::add($data->message, 'Error');
            return $this->serviceException();
        }

        return (object) array(
            'code' => $code,
            'mime' => $mime,
            'data' => $data,
            'cached' => False
        );
    }


    function getPdf(){
        // Access control
        if( !jAcl2::check("cadastre.acces.donnees.proprio") ){
            jMessage::add('Cadastre - Droits insuffisants pour accéder aux données de propriété', 'Error');
            return $this->serviceException();
        }

        $querystring = $this->constructUrl();
//jLog::log($querystring);
        // Get remote data
        $getRemoteData = lizmapProxy::getRemoteData(
          $querystring,
          $this->services->proxyMethod,
          $this->services->debugMode
        );
        $data = $getRemoteData[0];
        $mime = $getRemoteData[1];
        $code = $getRemoteData[2];

        jMessage::clearAll();

        return (object) array(
            'code' => $code,
            'mime' => $mime,
            'data' => $data,
            'cached' => False
        );
    }


    function getHtml(){
        // Access control
        if( !jAcl2::check("cadastre.acces.donnees.proprio") ){
            jMessage::add('Cadastre - Droits insuffisants pour accéder aux données de propriété', 'Error');
            return $this->serviceException();
        }

        $querystring = $this->constructUrl();
//jLog::log($querystring);
        // Get remote data
        $getRemoteData = lizmapProxy::getRemoteData(
          $querystring,
          $this->services->proxyMethod,
          $this->services->debugMode
        );
        $data = json_decode($getRemoteData[0]);
        $data = $data->data;
        $mime = $getRemoteData[1];
        $code = $getRemoteData[2];

        jMessage::clearAll();

        return (object) array(
            'code' => $code,
            'mime' => $mime,
            'data' => $data,
            'cached' => False
        );
    }

}
