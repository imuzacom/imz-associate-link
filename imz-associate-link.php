<?php
/*
* Plugin Name: imz-associate-link
* Plugin URI: https://imuza.com
* Description: amazon のアソシエイトリンクをショートコードから作成する
* Version: 1.0.0
* Author: imuza
* Author URI: https://imuza.com
* License: GPLv2
*/

if (! defined('ABSPATH')) {
	exit;
}

const AAL_ACCESS_KEY = 'アクセスキー';
const AAL_SECRET_KEY = 'シークレットキー';
const AAL_PARTNER_TAG = 'トラッキングID';

add_action('init', 'ImzAssociateLink::init');

class ImzAssociateLink
{
    private $wpdb;
    private $table;

    static function init()
    {
        return new self();
    }

    function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $this->wpdb->prefix . 'imz_aal_cache';
    
        if($this->wpdb->get_var("SHOW TABLES LIKE '$this->table'") != $this->table){
    
            $charset_collate = $this->wpdb->get_charset_collate();
                
            $sql = "CREATE TABLE $this->table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                asin varchar(20) NOT NULL,
                title varchar(255),
                url varchar(100),
                image varchar(100),
                author varchar(100),
                artist varchar(200),
                release_date varchar(20),
                created_datetime datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY asin (asin)
           ) $charset_collate;";
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        add_shortcode('aal', array($this, 'create_aal_link'));

    }

    function create_aal_link($atts)
    {
        if(!isset($atts[0])){
            return;
        }

        // 24時間以上前のデータ削除
        $now = current_time('mysql');
        $query = "DELETE FROM $this->table WHERE created_datetime < DATE_SUB(%s, INTERVAL 1 day)";
        $this->wpdb->query( $this->wpdb->prepare($query, $now));

        // キャッシュ用データベースから読み出す
        $asin = $atts[0];
        $query = "SELECT * FROM $this->table WHERE asin = %s";
        $row = $this->wpdb->get_row($this->wpdb->prepare($query, $asin));
        $data = array();
        $cache = 'yes'; // 確認用
    
        if(!empty($row)){
            // キャッシュデータがあればデータベースからデータ取得
            $url = $row->url;
            $image = $row->image;
            $title = $row->title;
            $author = $row->author;
            $artist = $row->artist;
            $release_date = $row->release_date;
        }else{
            // キャッシュデータがなければPA-APIでデータ取得
            // コードはスクラッチパッドで得たサンプルデータのまま
            $cache = 'no'; // 確認用
            $serviceName="ProductAdvertisingAPI";
            $region="us-west-2";
            $accessKey=AAL_ACCESS_KEY;
            $secretKey=AAL_SECRET_KEY;
            $partnerTag=AAL_PARTNER_TAG;
            $payload="{"
                    ." \"ItemIds\": ["
                    ."  \"$asin\""
                    ." ],"
                    ." \"Resources\": ["
                    ."  \"Images.Primary.Large\","
                      ."  \"ItemInfo.ByLineInfo\","
                    ."  \"ItemInfo.Classifications\","
                    ."  \"ItemInfo.ContentInfo\","
                    ."  \"ItemInfo.ExternalIds\","
                    ."  \"ItemInfo.Title\""
                    ." ],"
                    ." \"PartnerTag\": \"$partnerTag\","
                    ." \"PartnerType\": \"Associates\","
                    ." \"Marketplace\": \"www.amazon.co.jp\""
                    ."}";
            $host="webservices.amazon.co.jp";
            $uriPath="/paapi5/getitems";
            $awsv4 = new AwsV4 ($accessKey, $secretKey);
            $awsv4->setRegionName($region);
            $awsv4->setServiceName($serviceName);
            $awsv4->setPath ($uriPath);
            $awsv4->setPayload ($payload);
            $awsv4->setRequestMethod ("POST");
            $awsv4->addHeader ('content-encoding', 'amz-1.0');
            $awsv4->addHeader ('content-type', 'application/json; charset=utf-8');
            $awsv4->addHeader ('host', $host);
            $awsv4->addHeader ('x-amz-target', 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.GetItems');
            $headers = $awsv4->getHeaders ();
            $headerString = "";
            foreach ($headers as $key => $value) {
                $headerString .= $key . ': ' . $value . "\r\n";
            }
            $params = array (
                    'http' => array (
                        'header' => $headerString,
                        'method' => 'POST',
                        'content' => $payload
                   )
               );
            $stream = stream_context_create ($params);
            
            $fp = @fopen ('https://'.$host.$uriPath, 'rb', false, $stream);
    
            if (! $fp) {
                // データが戻ってこなければ何もしない
                return;
                // throw new Exception ("Exception Occured");
            }
            $response = @stream_get_contents ($fp);
            if ($response === false) {
                // データが戻ってこなければ何もしない
                return;
                // throw new Exception ("Exception Occured");
            }

            // JSONデータから必要なデータを$dataに取り出す
            $object = json_decode($response);
            $items    = $object->ItemsResult->Items;
            foreach ($items as $item) {
                $data['URL']          = $item->DetailPageURL;
                $data['Title']        = $item->ItemInfo->Title->DisplayValue;
                if (isset($item->Images->Primary->Large)) {
                    $data['Image'] = $item->Images->Primary->Large->URL;
                } else {
                    $data['Image'] = plugin_dir_url( __FILE__ ) . 'images/no_image.png';
                }
                if (isset($item->ItemInfo->ByLineInfo->Contributors)) {
                    $contributors = $item->ItemInfo->ByLineInfo->Contributors;
                    foreach($contributors as $contributor) {
                        $contributor_name = $contributor->Name;
                        switch ($contributor->Role) {
                            case '著':
                                if (isset($data['Author'])){
                                    $data['Author'] .= ',' . $contributor_name;
                                }else{
                                    $data['Author'] = $contributor_name;
                                }
                                break;
                            case 'アーティスト':
                            case '出演':
                                if (isset($data['Artist'])){
                                    $data['Artist'] .= ',' . $contributor_name;
                                }else{
                                    $data['Artist'] = $contributor_name;
                                }
                                break;
                        }
                    }
                    if (isset($data['Author'])) $data['Author'] = mb_substr($data['Author'], 0, 200);
                    if (isset($data['Artist'])) $data['Artist'] = mb_substr($data['Artist'], 0, 200);
                }
                if (in_array($item->ItemInfo->Classifications->ProductGroup->DisplayValue, array('Book', 'Digital Ebook Purchas'))) {
                    if (isset($item->ItemInfo->ContentInfo->PublicationDate)) {
                        $release_str     = $item->ItemInfo->ContentInfo->PublicationDate->DisplayValue;
                        $release_date    = new DateTime($release_str);
                        $data['Release'] = $release_date->format('Y/m/d');
                    }
                }
            }

            // キャッシュ用データベースに保存する
            $asin = $item->ASIN;
            $title = array_key_exists('Title', $data) ? $data['Title'] : '';
            $url = array_key_exists('URL', $data) ? $data['URL'] : '';
            $image = array_key_exists('Image', $data) ? $data['Image'] : '';
            $author = array_key_exists('Author', $data) ? $data['Author'] : '';
            $artist = array_key_exists('Artist', $data) ? $data['Artist'] : '';
            $release_date = array_key_exists('Release', $data) ? $data['Release'] : '';
            $created_datetime = current_time('mysql');
    
            $array = array(
                'asin' => $asin,
                'title' => $title,
                'url' => $url,
                'image' => $image,
                'author' => $author,
                'artist' => $artist,
                'release_date' => $release_date,
                'created_datetime' => $created_datetime,
           );
    
            $this->wpdb->insert($this->table, $array, array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));
        }
    
        $html =<<<EOM
<div class="p-amazon">
<!-- cache: {$cache} -->
<a class="p-amazon__link" href="{$url}" target="_blank" rel="noopener">
<img class="p-amazon__image" src="{$image}" alt="{$title}">
<p class="p-amazon__title">{$title}</p>
EOM;
    
        $html .= $author ? '<p class="p-amazon__author">' . $author . '</p>' : '';
        $html .= $artist ? '<p class="p-amazon__artist">' . $artist . '</p>' : '';
        $html .= $release_date ? '<p class="p-amazon__release">' . $release_date . '</p>' : '';
    
        $html .=<<<EOM
<p class="p-amazon__amazon">Amazon</p>
</a>
</div>
EOM;
    
        return $html;
    }
}

class AwsV4 {

    private $accessKey = null;
    private $secretKey = null;
    private $path = null;
    private $regionName = null;
    private $serviceName = null;
    private $httpMethodName = null;
    private $queryParametes = array ();
    private $awsHeaders = array ();
    private $payload = "";

    private $HMACAlgorithm = "AWS4-HMAC-SHA256";
    private $aws4Request = "aws4_request";
    private $strSignedHeader = null;
    private $xAmzDate = null;
    private $currentDate = null;

    public function __construct($accessKey, $secretKey) {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->xAmzDate = $this->getTimeStamp ();
        $this->currentDate = $this->getDate ();
    }

    function setPath($path) {
        $this->path = $path;
    }

    function setServiceName($serviceName) {
        $this->serviceName = $serviceName;
    }

    function setRegionName($regionName) {
        $this->regionName = $regionName;
    }

    function setPayload($payload) {
        $this->payload = $payload;
    }

    function setRequestMethod($method) {
        $this->httpMethodName = $method;
    }

    function addHeader($headerName, $headerValue) {
        $this->awsHeaders [$headerName] = $headerValue;
    }

    private function prepareCanonicalRequest() {
        $canonicalURL = "";
        $canonicalURL .= $this->httpMethodName . "\n";
        $canonicalURL .= $this->path . "\n" . "\n";
        $signedHeaders = '';
        foreach ($this->awsHeaders as $key => $value) {
            $signedHeaders .= $key . ";";
            $canonicalURL .= $key . ":" . $value . "\n";
        }
        $canonicalURL .= "\n";
        $this->strSignedHeader = substr ($signedHeaders, 0, - 1);
        $canonicalURL .= $this->strSignedHeader . "\n";
        $canonicalURL .= $this->generateHex ($this->payload);
        return $canonicalURL;
    }

    private function prepareStringToSign($canonicalURL) {
        $stringToSign = '';
        $stringToSign .= $this->HMACAlgorithm . "\n";
        $stringToSign .= $this->xAmzDate . "\n";
        $stringToSign .= $this->currentDate . "/" . $this->regionName . "/" . $this->serviceName . "/" . $this->aws4Request . "\n";
        $stringToSign .= $this->generateHex ($canonicalURL);
        return $stringToSign;
    }

    private function calculateSignature($stringToSign) {
        $signatureKey = $this->getSignatureKey ($this->secretKey, $this->currentDate, $this->regionName, $this->serviceName);
        $signature = hash_hmac ("sha256", $stringToSign, $signatureKey, true);
        $strHexSignature = strtolower (bin2hex ($signature));
        return $strHexSignature;
    }

    public function getHeaders() {
        $this->awsHeaders ['x-amz-date'] = $this->xAmzDate;
        ksort ($this->awsHeaders);

        // Step 1: CREATE A CANONICAL REQUEST
        $canonicalURL = $this->prepareCanonicalRequest ();

        // Step 2: CREATE THE STRING TO SIGN
        $stringToSign = $this->prepareStringToSign ($canonicalURL);

        // Step 3: CALCULATE THE SIGNATURE
        $signature = $this->calculateSignature ($stringToSign);

        // Step 4: CALCULATE AUTHORIZATION HEADER
        if ($signature) {
            $this->awsHeaders ['Authorization'] = $this->buildAuthorizationString ($signature);
            return $this->awsHeaders;
        }
    }

    private function buildAuthorizationString($strSignature) {
        return $this->HMACAlgorithm . " " . "Credential=" . $this->accessKey . "/" . $this->getDate () . "/" . $this->regionName . "/" . $this->serviceName . "/" . $this->aws4Request . "," . "SignedHeaders=" . $this->strSignedHeader . "," . "Signature=" . $strSignature;
    }

    private function generateHex($data) {
        return strtolower (bin2hex (hash ("sha256", $data, true)));
    }

    private function getSignatureKey($key, $date, $regionName, $serviceName) {
        $kSecret = "AWS4" . $key;
        $kDate = hash_hmac ("sha256", $date, $kSecret, true);
        $kRegion = hash_hmac ("sha256", $regionName, $kDate, true);
        $kService = hash_hmac ("sha256", $serviceName, $kRegion, true);
        $kSigning = hash_hmac ("sha256", $this->aws4Request, $kService, true);

        return $kSigning;
    }

    private function getTimeStamp() {
        return gmdate ("Ymd\THis\Z");
    }

    private function getDate() {
        return gmdate ("Ymd");
    }
}
