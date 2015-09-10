<?php
require_once("vendors/IXR_Library.php");

class IMPORT_30_DAYS
{
    public $sUrlProduction = "***prod_url_xmlrpcn***";
    public $oClientProduction = null;

    public $sLgProduction = "***prod_login***";
    public $sPwProduction = "***prod_pass***";

    public $sAuthLgProduction = "";
    public $sAuthPwProduction = "";

    public $iBlogIdProduction = 0;


    public $sUrlStaging = "***stag_url_xmlrpcn***";
    public $oClientStaging = null;

    public $sLgStaging = "***stag_login***";
    public $sPwStaging = "***stag_pass***";

    public $sAuthLgStaging = "";
    public $sAuthPwStaging = "";

    public $iBlogIdStaging = 0;

    public $bDebugMode = false;
    public $sTimeCreatePost = "01-01-2013";

    private $_aCategoryTerm;

//----------------------------------------------------------

    public function __construct() {
        date_default_timezone_set('Europe/Moscow');

        $this->oClientProduction = new IXR_Client( $this->sUrlProduction );

        if( strlen( $this->sAuthLgProduction ) > 0 ) {
            $this->oClientProduction->username = $this->sAuthLgProduction;
            $this->oClientProduction->password = $this->sAuthPwProduction;
        }

        $this->oClientStaging = new IXR_Client( $this->sUrlStaging );

        if( strlen( $this->sAuthLgStaging ) > 0 ) {
            $this->oClientStaging->username = $this->sAuthLgStaging;
            $this->oClientStaging->password = $this->sAuthPwStaging;
        }

        if( $this->bDebugMode) {
            $this->oClientProduction->debug = true;
            $this->oClientStaging->debug = true;
        }

        $this->_aCategoryTerm = $this->_get_terms_staging( 'category' );

        $this->run( 10 );
    }
    
    public function run( $iCountPostInStep) {

        $this->write('Start');

        $iStep = 0;
        $iCount30DaysPost = 1;

        $post30days = array();

        do {
            // get 30 days data from staging
            $requestData = array( $this->iBlogIdStaging, $this->sLgStaging, $this->sPwStaging );
            $requestData[] = array( 'post_type' => '30_days_page', 'number' => $iCountPostInStep, 'offset' => $iStep * $iCountPostInStep );

            $iStep++;

            if (!$this->oClientStaging->query('wp.getPosts', $requestData)) {
                $this->write( 'error get 30 days post: ' . $this->oClientStaging->getErrorMessage() );
            }
            else {
                $post30days = $this->oClientStaging->getResponse();

                foreach ( $post30days as $post) {

                    $this->write($iCount30DaysPost . ") 30days id: " . $post["post_id"] . " '" . $post["post_title"] . "'");

                    $this->_import_post($post);

                    $iCount30DaysPost ++;
                }
            }
        } while( count( $post30days ) > 0);
        $this->write('All done.');
    }

    private function _import_post( $oPost30Days ) {

        // get data about posts from custom_fields
        foreach( $oPost30Days['custom_fields'] as $oPost30DaysCustomField ) {

            if( strcmp( $oPost30DaysCustomField['key'], 'sc_30_days_page') == 0 ) {

                $oValue30DaysPages = unserialize( $oPost30DaysCustomField['value'] );

                $bChange30DaysPost = false;

                foreach( $oValue30DaysPages["posts"] as $sKeyPost => $oPost) {

                    if( $this->_check_post_on_staging( $oPost['id'] ) ) {
                        $this->write(' - Post ' . $oPost['id'] . ' exist' );
                    }
                    else {
                        $sNewPostId = $this->_generate_new_post( $oPost['id'], $oPost30Days['post_id'] );

                        if( $sNewPostId ) {

                            $oValue30DaysPages["posts"][ $sKeyPost ]['id'] = $sNewPostId;

                            $bChange30DaysPost = true;
                        }
                    }
                }

                if( $bChange30DaysPost )
                    $this->_edit_post_custom_fields( $oPost30Days['post_id'], $oPost30DaysCustomField['id'], $oValue30DaysPages );
            }
        }
    }

    private function _generate_new_post( $sPostStagingId, $sPost30DaysId ) {

        $this->write(' - Post id: ' . $sPostStagingId );

        // get post from production
        $requestData = array( $this->iBlogIdProduction, $this->sLgProduction, $this->sPwProduction, $sPostStagingId );

        if ( ! $this->oClientProduction->query( 'wp.getPost', $requestData ) ) {
            $this->write( '- - error get post from production: ' . $this->oClientProduction->getErrorMessage() );
        }
        else {

            $this->write(' - - get post from production' );

            return $this->_create_new_post_on_staging( $this->oClientProduction->getResponse(), $sPost30DaysId );
        }

        return false;
    }

    private function _create_new_post_on_staging( $oPostData, $sPost30DaysId )
    {
        // down load feature image of post

        $postFeatureImageId = false;
        $sNewFeatureImageId = false;

        foreach ($oPostData['custom_fields'] as $meta) {
            if ($meta['key'] == '_thumbnail_id') {
                $postFeatureImageId = $meta['value'];
            }
        }

        if ($postFeatureImageId) {

            $this->write(' - - - feature image id: ' . $postFeatureImageId);

            // check existing feature image on staging
            if ( $this->oClientStaging->query('wp.getMediaItem', array( $this->iBlogIdStaging, $this->sLgStaging, $this->sPwStaging, $postFeatureImageId) ) ) {

                $sNewFeatureImageId = $postFeatureImageId;

                $this->write(' - - - feature image exist: ' . $sNewFeatureImageId);
            } else {
                $sNewFeatureImageId = $this->load_attachment($postFeatureImageId);
            }
        }

        // create new post on staging

        // add info about 30 days
        $aCustoms = array( );
        $aCustoms[] = array( 'key' => 'sc_30_days_page', 'value' => $sPost30DaysId );
        foreach ($oPostData['custom_fields'] as $oCustom) {

            if( strcmp( $oCustom['key'], 'sc_30_days_post_image') == 0) {
                $aCustoms[] = array( 'key' => 'sc_30_days_post_image', 'value' => $oCustom['value'] );
            }
        }

        // add info about categories
        $aTerms = array( );
        foreach ($oPostData['terms'] as $oTerm) {
            if( strcmp( $oTerm['taxonomy'], 'category') == 0) {

                foreach ( $this->_aCategoryTerm as $oCategoryTerm) {
                    if( strcmp( $oTerm['slug'], $oCategoryTerm['slug'] ) == 0 ) {
                        $aTerms[] = $oCategoryTerm['term_id'];
                    }
                }
            }
        }

        $requestData = array( $this->iBlogIdStaging, $this->sLgStaging, $this->sPwStaging );
        $requestData[3] = array(    "post_type" => $oPostData['post_type'],
                                    "post_status" => $oPostData['post_status'],
                                    "post_title" => $oPostData['post_title'],
                                    "post_excerpt" => $oPostData['post_excerpt'],
                                    "post_content" => $oPostData['post_content'],
                                    "post_date_gmt" => new IXR_Date( strtotime( $this->sTimeCreatePost) ),
                                    "post_format" => $oPostData['post_format'],
                                    "post_name" => $oPostData['post_name'],
                                    "comment_status" => $oPostData['comment_status'],
                                    "ping_status" => $oPostData['ping_status'],
                                    "sticky" => $oPostData['sticky'],
                                    "post_parent" => $oPostData['post_parent'],
                                    "custom_fields" => $aCustoms,
                                    "terms" => array( 'category' => $aTerms ));

        if( $sNewFeatureImageId )
            $requestData[3]['post_thumbnail'] = $sNewFeatureImageId;

        if ( ! $this->oClientStaging->query( 'wp.newPost', $requestData ) ) {
            $this->write(' - - error post create: ' . $this->oClientStaging->getErrorMessage() );
        }
        else {
            $this->write(' - - generate post: ' . $this->oClientStaging->getResponse());
            return $this->oClientStaging->getResponse();
        }

        return false;
    }

    private function _check_post_on_staging( $sPostId ) {

        if ( ! $this->oClientStaging->query( 'wp.getPost', array( $this->iBlogIdStaging, $this->sLgStaging, $this->sPwStaging, $sPostId ) ) ) {
            return false;
        }
        else {
            return true;
        }
    }

    private function load_attachment( $iAttachmentId ) {

        // get attachment data from production
        if (!$this->oClientProduction->query('wp.getMediaItem', array( $this->iBlogIdProduction, $this->sLgProduction, $this->sPwProduction, $iAttachmentId) )) {
            $this->write( ' - - - image absent on production' );
            return false;
        }
        else {

            $oMedia = $this->oClientProduction->getResponse();

            $sUrlFile = $oMedia['link'];

            $this->write( ' - - - link: ' . $sUrlFile );

            $sNameFile = basename( $sUrlFile);

            if( strlen( $sNameFile ) <= 0) {
                $this->write( ' - - - empty file name ' );
                return false;
            }

            //download image
            $countRepeat = 1;
            $bits = false;
            //repeat three times when an error
            while (!$bits AND $countRepeat <= 3) {
                $this->write(" - - - try download image: " . $sUrlFile );
                $bits = file_get_contents( $sUrlFile );
                $countRepeat++;
                if (!$bits) {
                    sleep( 1 );
                }
            }

            if (!$bits) {
                $this->write(" - - - error download image: ".$sUrlFile);
                return false;
            }

            $finfo = new finfo(FILEINFO_MIME);
            $sMimeType = str_replace('; charset=binary', '', $finfo->buffer($bits));

            // upload image to staging
            $requestData = array( $this->iBlogIdStaging, $this->sLgStaging, $this->sPwStaging);
            $requestData[3] = array( 'name' => $sNameFile, 'type' => $sMimeType, 'bits' => new IXR_Base64($bits), 'overwrite' => false );

            if (!$this->oClientStaging->query('wp.uploadFile', $requestData)) {

                $this->write( " - - - error image upload: " . $this->oClientStaging->getErrorMessage());
            }
            else {

                $oRespUpload = $this->oClientStaging->getResponse();

                $this->write( " - - - image upload: " . $oRespUpload['id']);

                return $oRespUpload['id'];
            }
        }

        return false;
    }

    private function _get_terms_staging( $sTaxonomy ) {
        $requestData = array( $this->iBlogIdStaging, $this->sLgStaging, $this->sPwStaging, $sTaxonomy );

        if ( ! $this->oClientStaging->query( 'wp.getTerms', $requestData ) ) {
            $this->write( 'Error get terms: '. $this->oClientStaging->getErrorMessage() );
            return array();
        }
        else {
            return $this->oClientStaging->getResponse();
        }
    }

    private function _edit_post_custom_fields( $iIdPost, $iIdCustomField, $aValue) {

        $requestData = array( $this->iBlogIdStaging, $this->sLgStaging, $this->sPwStaging, $iIdPost);
        $requestData[4] = array( 'custom_fields' => array( array( "id" => $iIdCustomField, 'key' => 'sc_30_days_page', 'value' => $aValue ) ) );

        if (!$this->oClientStaging->query('wp.editPost', $requestData)) {
            $this->write( ' - error 30 days post edit: ' . $this->oClientStaging->getErrorMessage() );
        }
        else {
            $this->write(' - 30 days post ' . $iIdPost . ' edit');
        }
    }

    
    private function write( $text ) {
        $now = new DateTime();
        echo $now->format("Y-m-d H:i:s") . " : $text\n";
    }
}

new IMPORT_30_DAYS();
?>