<?php
/**
* @package   lizmap
* @subpackage cadastre
* @author    3liz
* @copyright 2016 3liz
* @link      http://3liz.com
* @license Mozilla Public License : http://www.mozilla.org/MPL/
*/

class serviceCtrl extends jController {

    /**
    * Get PDF generated by QGIS Server Cadastre plugin
    * @param project Project key
    * @param repository Repository key
    * @param layer Name of the Parcelle layer
    * @param parcelle ID of the parcelle ( field geo_parcellle )
    * @param type Type of export: parcelle or proprietaire
    */
    function getCadastrePdf() {

        $project = $this->param('project');
        $repository = $this->param('repository');

        $rep = $this->getResponse('json');

        if( !jAcl2::check("cadastre.acces.donnees.proprio") ){
            $rep->data = array('status'=>'error', 'message'=>'Vous n\'avez pas les droits pour voir les données de propriété.');
            return $rep;
        }

        $services = lizmap::getServices();
        if (version_compare($services->qgisServerVersion, '3.0', '<') &&
            !preg_match('#^cadastre#i', $project) ){
            $rep->data = array('status'=>'error', 'message'=>'This is not a cadastre project. Project key must begins with cadastre');
            return $rep;
        }

        $p = lizmap::getProject($repository.'~'.$project);
        if( !$p ){
            $rep->data = array('status'=>'error', 'message'=>'A problem occured while loading project with Lizmap');
            return $rep;
        }

        jClasses::inc('cadastre~lizmapCadastreRequest');
        if (version_compare($services->qgisServerVersion, '3.0', '>=')) {
            $request = new lizmapCadastreRequest(
                $p,
                array(
                    'service'=>'CADASTRE',
                    'request'=>'GetCapabilities'
                )
            );
            $result = $request->process();
            if ($result->code !== 200){
                $rep->data = array('status'=>'error', 'message'=>'This is not a cadastre project or has not been configured.');
                return $rep;
            }
        }

        $parcelleLayer = $this->param('layer');
        $parcelleId = $this->param('parcelle');
        $type = $this->param('type');
        if(!$parcelleLayer or !$parcelleId or !$type){
            $rep->data = array('status'=>'error', 'message'=>'layer, parcelle and type parameters are mandatory');
            return $rep;
        }

        if($type == 'fiche'){
            $creq = 'getHtml';
            //jLog::log($creq);
        }else{
            $creq = 'createPdf';
        }
        $request = new lizmapCadastreRequest(
            $p,
            array(
                'service'=>'CADASTRE',
                'request'=>$creq,
                'layer'=> $parcelleLayer,
                'parcelle'=> $parcelleId,
                'type'=> $type
            )
        );
        $result = $request->process();

        // Check errors
        if($result->mime == 'text/xml'){
            $rep->data = array('status'=>'error', 'message'=> trim(preg_replace( "#\n#", '', strip_tags($result->data))));
            return $rep;
        }

        if($type == 'fiche'){
            $rep = $this->getResponse('htmlfragment');
            $rep->addContent($result->data);
            return $rep;
        }

        // Get created PDFs;
        $data = $result->data;
        $pdfs = array();
        $tok = Null;
        foreach( $data->data->tokens as $token ){
            $tok = $token;
            $request = new lizmapCadastreRequest(
                $p,
                array(
                    'service'=>'CADASTRE',
                    'request'=>'getPdf',
                    'token'=> $token
                )
            );
            $result = $request->process();
            if( $result->mime != 'application/pdf'){
                continue;
            }
            $pdfs[$token] = $result->data;
        }
        if( count($pdfs) == 1 ){
            $rep = $this->getResponse('binary');
            $rep->mimeType = 'application/pdf';
            $rep->content = $pdfs[$tok];
            $rep->doDownload  =  false;
            $rep->outputFileName = 'cadastre_' . $tok . '.pdf';
        }else if(count($pdfs) == 0){
            $rep = $this->getResponse('text');
            $rep->content = 'Erreur de création du relevé.';
            return $rep;
        }else{
            $rep = $this->getResponse('zip');
            $rep->zipFilename='releves_cadastre.zip';
            foreach( $pdfs as $token=>$pdf ){
                $rep->content->addContentFile('cadastre_' . $token . '.pdf', $pdf);
            }
        }

        return $rep;
    }


    /**
     * Autocompletion search
     *
    */
    function autocomplete() {

        $rep = $this->getResponse('json');

        $term = $this->param('term');
        $field = $this->param('field', 'voie');
        $commune = $this->param('commune');
        $voie = $this->param('voie');
        $limit = $this->intParam('limit', 30);

        $project = $this->param('project');
        $repository = $this->param('repository');

        // Get profile
        $parcelleLayer = $this->param('layer', 'Parcelles');
        //$profile = cadastreProfile::get($repository, $project, $parcelleLayer);

        $autocomplete = jClasses::getService('cadastre~search');
        try {
            $result = $autocomplete->getData( $repository, $project, $parcelleLayer, $term, $field, $commune, $voie, $limit );
        } catch (Exception $e) {
            $result = Null;
        }

        $rep->data = $result;

        return $rep;
    }

    /**
     * Get total extent for road or owner
     *
    */
    function extent() {

        $rep = $this->getResponse('json');

        $field = $this->param('field', 'voie');
        $value = $this->param('value');

        $project = $this->param('project');
        $repository = $this->param('repository');

        $parcelleLayer = $this->param('layer', 'Parcelles');

        $autocomplete = jClasses::getService('cadastre~search');
        $result = $autocomplete->getDataExtent( $repository, $project, $parcelleLayer, $field, $value );

        $rep->data = $result;

        return $rep;
    }



    /**
     * Export data
     *
    */
    function initExport() {

        $rep = $this->getResponse('json');
        $services = lizmap::getServices();
        // Use standard export if the standalone python PDF generator does not exist
        $standalone_python_script = '/srv/qgis/plugins/cadastre/standalone_export.py';
        if (version_compare($services->qgisServerVersion, '3.0', '>=')) {
            $standalone_python_script = '/srv/qgis/plugins/cadastre/standalone/export.py';
        }
        if(!is_file($standalone_python_script)){
            return $this->getCadastrePdf();
        }

        // params
        $project = $this->param('project');
        $repository = $this->param('repository');
        $parcelleLayer = $this->param('layer', 'Parcelles');
        $parcelleId = $this->param('parcelle');
        $type = $this->param('type');

        // get needed values
        $p = lizmap::getProject($repository.'~'.$project);

        // checks
        if( !jAcl2::check("cadastre.acces.donnees.proprio") ){
            $rep->data = array('status'=>'error', 'message'=>'Vous n\'avez pas les droits pour voir les données de propriété.');
            return $rep;
        }
        if( !$p ){
            $rep->data = array('status'=>'error', 'message'=>'A problem occured while loading project with Lizmap');
            return $rep;
        }

        // Create token
        $token = md5($repository . $project . $parcelleLayer . $parcelleId . $type . microtime(true));
        $_SESSION['cadastre_export_'.$token] = 'wait';

        // Create file path
        $log = jApp::tempPath($token . '.log');

        // Run python code
        $cmd = 'python ' . $standalone_python_script;
        if (version_compare($services->qgisServerVersion, '3.0', '>=')) {
            $cmd = 'python3 ' . $standalone_python_script;
        }
        $cmd.= ' -P ' . $p->getQgisPath();
        $cmd.= ' -L "' . $parcelleLayer . '"';
        $cmd.= ' -I ' . $parcelleId;
        $cmd.= ' -T ' . $type;
        $cmd.= ' -D ' . jApp::tempPath();
        $cmd.= ' -O ' . $log;
//jLog::log($cmd);
        $this->execInBackground($cmd);

        // Redirect to display page
        $rep = $this->getResponse('redirect');
        $rep->action = 'cadastre~service:waitExport';
        $rep->params = $this->params();
        $rep->params['token'] = $token;

        return $rep;
    }

    private function execInBackground($cmd) {
        if (substr(php_uname(), 0, 7) == "Windows"){
            pclose(popen("start /B ". $cmd, "r"));
        }
        else {
            exec($cmd . " > /dev/null &");
        }
    }

    function waitExport() {

        $rep = $this->getResponse('json');

        // params
        $project = $this->param('project');
        $repository = $this->param('repository');
        $token = $this->param('token');

        // get needed values
        $p = lizmap::getProject($repository.'~'.$project);

        // checks
        if( !jAcl2::check("cadastre.acces.donnees.proprio") ){
            $rep->data = array('status'=>'error', 'message'=>'Vous n\'avez pas les droits pour voir les données de propriété.');
            return $rep;
        }
        if( !$p ){
            $rep->data = array('status'=>'error', 'message'=>'A problem occured while loading project with Lizmap');
            return $rep;
        }

        $rep = $this->getResponse('html');
        $rep->title = 'Export des données cadastrales';
        $rep->body->assign('repositoryLabel', 'Cadastre');
        $rep->body->assign('isConnected', jAuth::isConnected());
        $rep->body->assign('user', jAuth::getUserSession());

        // Add JS code to refresh
        $rep->addJSCode("var token = '" . $token . "'; ");
        $checkUrl = jUrl::getFull(
            'cadastre~service:checkExport',
            $this->params()
        );
        $rep->addJSCode("var checkUrl = '" . $checkUrl . "'; ");
        $jslink = jUrl::get(
            'jelix~www:getfile',
            array('targetmodule'=>'cadastre', 'file'=>'cadastre-export.js')
        );
        $rep->addJSLink($jslink);
        $tpl = new jTpl();
        $rep->body->assign('MAIN', "<div id=\"waitExport\">L'export est en cours...</div>");

        return $rep;
    }

    function checkExport() {
        $rep = $this->getResponse('json');

        // params
        $project = $this->param('project');
        $repository = $this->param('repository');
        $token = $this->param('token');

        // Get log path
        $log = jApp::tempPath($token . '.log');
        $logcontent = jFile::read($log);
        if(!array_key_exists('cadastre_export_'.$token, $_SESSION) ){
            $data = array(
                'status'=> 'error',
                'message' => 'La requête est périmée.'
            );
        }else{
            $ses = $_SESSION['cadastre_export_'.$token];
            if(!empty($logcontent)){
                $data = array(
                    'status'=> 'ok',
                    'url'=> jUrl::getFull(
                        'cadastre~service:downloadExport',
                        $this->params()
                    ),
                    'message'=> 'Le fichier a bien été créé'
                );
            }else{
                $data = array(
                    'status'=> 'wait',
                    'message'=> 'L\'export est en cours...'
                );
            }
        }
        $rep->data = $data;

        return $rep;
    }


    function downloadExport() {
        $rep = $this->getResponse('json');

        // params
        $project = $this->param('project');
        $repository = $this->param('repository');
        $token = $this->param('token');

        // get needed values
        $p = lizmap::getProject($repository.'~'.$project);

        // checks
        if( !jAcl2::check("cadastre.acces.donnees.proprio") ){
            $rep->data = array('status'=>'error', 'message'=>'Vous n\'avez pas les droits pour voir les données de propriété.');
            return $rep;
        }
        if( !$p ){
            $rep->data = array('status'=>'error', 'message'=>'A problem occured while loading project with Lizmap');
            return $rep;
        }

        // Get log path
        $log = jApp::tempPath($token . '.log');
        $logcontent = jFile::read($log);
        if(!array_key_exists('cadastre_export_'.$token, $_SESSION) ){
            $data = array(
                'status'=> 'error',
                'message' => 'La requête est périmée.'
            );
            $rep->data = $data;
            return $rep;
        }else{
            if(!empty($logcontent)){
                if(is_file($logcontent)){
                    $rep = $this->getResponse('binary');
                    $rep->mimeType = 'application/pdf';
                    $rep->content = jFile::read($logcontent);
                    $rep->doDownload  =  false;
                    $rep->outputFileName = 'cadastre_' . $token . '.pdf';
                    unlink($logcontent);
                    unlink($log);
                    unset($_SESSION['cadastre_export_'.$token]);
                    return $rep;
                }else{
                    $files = explode('.pdf', $logcontent);
                    $rep = $this->getResponse('zip');
                    $rep->zipFilename = "Export_cadastre_$token.zip";
                    foreach($files as $file){
                        $fp = $file. '.pdf';
                        if(is_file($fp)){
                            $rep->content->addContentFile(basename($fp), jFile::read($fp));
                            unlink($fp);
                        }
                    }
                    unlink($log);
                    unset($_SESSION['cadastre_export_'.$token]);
                    return $rep;
                }

            }else {
                $rep->data = array('status'=>'error', 'message'=>"La requête est périmée.");
                return $rep;
            }
        }
    }


    function locauxProprios() {

        $rep = $this->getResponse('json');

        if( !jAcl2::check("cadastre.acces.donnees.proprio") ) {
            $rep->data = array(
                'status'=>'error',
                'message'=>'Vous n\'avez pas les droits pour voir les données de propriété.'
            );
            return $rep;
        }

        $project = $this->param('project');
        $repository = $this->param('repository');
        $p = lizmap::getProject($repository.'~'.$project);
        if( !$p ){
            $rep->data = array('status'=>'error', 'message'=>'A problem occured while loading project with Lizmap');
            return $rep;
        }

        $parcelleIds = $this->param('parcelles');
        $parcelleIds = explode( ',', $parcelleIds );

        $rep = $this->getResponse('binary');
        $rep->mimeType = 'text/csv';

        // Get profile
        $parcelleLayer = $this->param('layer', 'Parcelles');
        $profile = cadastreProfile::get($repository, $project, $parcelleLayer);

        $extra_infos = jClasses::getService('cadastre~cadastreExtraInfos');
        $path = $extra_infos->getLocauxAndProprioInfos($profile, $parcelleIds);

        $rep->fileName = $path;
        $rep->deleteFileAfterSending = True;

        $rep->doDownload = True;
        $rep->outputFileName = 'cadastre_extra_infos.csv';

        return $rep;
    }

}

