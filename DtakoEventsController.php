<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\DtakoEvent;
use App\Model\Entity\DtakoRow;
use App\Model\Entity\DtakoUriageKeihi;
use Cake\Controller\Controller;
use Cake\Database\Expression\QueryExpression;
use Cake\Datasource\ConnectionManager;

use Cake\Filesystem\Folder;
use Cake\Filesystem\File;
use Cake\I18n\FrozenDate;
use ZipArchive;
use Cake\I18n\Time;
use Cake\ORM\Query;
use Cake\ORM\Table;
use DateTime;
use phpDocumentor\Reflection\Types\Boolean;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Cake\Core\Configure;
use Exception;

/**
 * DtakoEvents Controller
 *
 * @property \App\Model\Table\RyohiRowsTable $RyohiRows
 * @property \App\Model\Table\DtakoEventsTable $DtakoEvents
 * @property \App\Model\Table\DtakoRowsTable $DtakoRows
 * @method \App\Model\Entity\DtakoEvent[]|\Cake\Datasource\ResultSetInterface paginate($object = null, array $settings = [])
 */
class DtakoEventsController extends AppController
{
    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    protected $DtakoRows;
    protected $DtakoFerryRows;
    protected $RyohiRows;
    protected $SokudoData;
    public function index()
    {

        $this->loadModel('DtakoRows');
        $DtakoRows = $this->DtakoRows->find()->contain('Drivers')->where(function ($q) {
            return $q->gte('帰庫日時', '2021-12-1');
        });
        $this->set(compact('DtakoRows'));
    }


    function findEmpty()
    {

        $empty = $this->DtakoEvents->find()
            ->where([
                "開始市町村名 is " => "",
                function (QueryExpression $lt) {
                    return $lt->gt("開始日時", new FrozenDate("-1week"))
                        ->gt("開始GPS緯度", 0)
                        ->gt("開始GPS経度", 0)
                        ->notIn("イベント名", ["アイドリング"]);
                }
            ])
            ->contain("Drivers")
            ->orderDesc("開始日時");
        $this->set(compact("empty"));
    }



    // Encode a string to URL-safe base64
    function _encodeBase64UrlSafe($value)
    {
        return str_replace(
            array('+', '/'),
            array('-', '_'),
            base64_encode($value)
        );
    }


    // Sign a URL with a given crypto key
    // Note that this URL must be properly URL-encoded
    function signUrl($myUrlToSign)
    {
        // parse the url
        $url = parse_url($myUrlToSign);

        $urlPartToSign = $url['path'] . "?" . $url['query'];

        // Decode the private key into its binary format

        /**
         * @var mixed $APIKEY 
         * @link  https://tool-engineer.work/article80/ */
        $APIKEY = Configure::read("APIKEY");
        $decodedKey = $this->_decodeBase64UrlSafe($APIKEY);

        // Create a signature using the private key and the URL-encoded
        // string using HMAC SHA1. This signature will be binary.
        $signature = hash_hmac("sha1", $urlPartToSign, $decodedKey,  true);

        $encodedSignature = $this->_encodeBase64UrlSafe($signature);

        return $myUrlToSign . "&signature=" . $encodedSignature;
    }
    // Decode a string from URL-safe base64
    function _decodeBase64UrlSafe($value)
    {
        return base64_decode(str_replace(
            array('-', '_'),
            array('+', '/'),
            $value
        ));
    }



    function setLocationByGeo($srch_id = null)
    {
        if ($srch_id == null) {
            $this->redirect("/");
        }
        /**TODO:　住所取得関数の作成 */
        // $empty = $this->DtakoEvents->find()
        //     ->where([
        //         "開始市町村名 is " => "",
        //         function (QueryExpression $lt) {
        //             return $lt->gt("開始日時", new FrozenDate("-1week"))
        //                 ->gt("開始GPS緯度",0)
        //                 ->gt("開始GPS経度",0)
        //                 ->notIn("イベント名", ["アイドリング"]);
        //         }
        //     ])
        //     ->orderDesc("開始日時");
        $empty = $this->DtakoEvents->find()->where(
            ["srch_id" => $srch_id]
        )->first();
        // $url = 'https://umayadia-apisample.azurewebsites.net/api/persons';
        if ($empty == null) {
            $this->Flash->error("could not find srch_id");
            $this->redirect("/");
        }
        // dd($empty);

        /**
         * @var mixed $APIKEY 
         * @link  https://tool-engineer.work/article80/ */
        $APIKEY = Configure::read("APIKEY");
        $lat = (string)($empty->開始GPS緯度 / 1000000);
        $lon = (string)($empty->開始GPS経度 / 1000000);
        $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng=" . $lat . "," . $lon . "&key=" . $APIKEY . "&language=ja";

        // ストリームコンテキストのオプションを作成
        $options = array(
            // HTTPコンテキストオプションをセット
            'http' => array(
                'method' => 'GET',
                'header' => 'Content-type: application/json; charset=UTF-8' //JSON形式で表示
            )
        );


        // ストリームコンテキストの作成
        $context = stream_context_create($options);

        $raw_data = file_get_contents($url, false, $context);

        $data = json_decode($raw_data, true);
        // dd($data);
        $data_r = $data["results"][1]["address_components"];
        krsort($data_r);
        // dd($data_r);
        $data_rr = implode("", array_slice(array_column($data_r, "short_name"), 2, 3));
        // dd($data_r);
        // $data_rr="熊本県天草市志柿町";
        $tmp_data = $this->DtakoEvents->DtakoEventsPlaces->newEntity($empty->toArray());
        $tmp_data->開始市町村名 = $data_rr;
        if ($this->DtakoEvents->DtakoEventsPlaces->save($tmp_data)) {
            $this->Flash->success("inserted DtakoEventsPlaces");
            $empty->開始市町村名 = $data_rr;
            $this->DtakoEvents->save($empty);
        } else {
            $this->Flash->error("Cannot inserted DtakoEventsPlaces");
        }
        $this->redirect($this->request->getQuery("redirect", "/"));
    }

    public function setGeoCode()
    {
        $this->_setGeoCode();
        $this->redirect("/");
    }

    public function _setGeoCode()
    {
        $ds1 = $this->DtakoEvents->find()->where([function (QueryExpression $q) {
            return $q->gte("開始日時", new FrozenDate("-1 week"));
        }])->notMatching("Geocode")->distinct(["開始GPS緯度", "開始GPS経度"])->where(["開始市町村名 is not " => '']);
        // dd($ds1->count());
        foreach ($ds1 as $vv) {
            if (!$this->DtakoEvents->Geocode->exists(["lat" => $vv->開始GPS緯度, "lon" => $vv->開始GPS経度])) {
                $new_entity =   $this->DtakoEvents->Geocode->newEntity(["lat" => $vv->開始GPS緯度, "lon" => $vv->開始GPS経度, "city_cd" => $vv->開始市町村CD, "geo_name" => $vv->開始市町村名]);
                $this->DtakoEvents->Geocode->save($new_entity);
            }
            // dd($new_entity);
        };

        $ds1 = $this->DtakoEvents->find()->where([function (QueryExpression $q) {
            return $q->gte("開始日時", new FrozenDate("-2 week"));
        }])->notMatching("GeocodeEnd")->distinct(["終了GPS緯度", "終了GPS経度"])->where(["終了市町村名 is not " => '']);
        foreach ($ds1 as $vv) {
            if (!$this->DtakoEvents->Geocode->exists(["lat" => $vv->終了GPS緯度, "lon" => $vv->終了GPS経度])) {
                $new_entity =   $this->DtakoEvents->Geocode->newEntity([
                    "lat" => $vv->終了GPS緯度,
                    "lon" => $vv->終了GPS経度,
                    "city_cd" => $vv->終了市町村CD,
                    "geo_name" => $vv->終了市町村名
                ]);
                $this->DtakoEvents->Geocode->save($new_entity);
            }
            // dd($new_entity);
        };

        // $this->redirect("/");
    }


    public function ichibandtakocheck()
    {
        // $date = "2022-9-1";
        $this->loadModel('DtakoRows');
        $start = microtime(true);

        $date = new FrozenDate();
        $date = $date->modify("-2 months");
        // dump(microtime(true) - $start);



        $dr_list = $this->DtakoEvents
            // ->find('list', ['valueField' => '運行NO'])
            ->find()
            ->select(['id' => 'DtakoEvents.運行NO'])
            ->matching('Cars.BunruiOffices', function ($q) {
                return $q->where(['BunruiOffices.office_id in' => [0, 1]]);
            })
            ->where([
                'イベント名' => '積み',
                '得意先 is' => null
            ])
            ->notMatching('DtakoEventsDetails')
            ->where(function ($exp) use ($date) {
                return $exp
                    ->gte('開始日時', $date);
            })->leftJoinWith('DtakoRows')
            ->where(['right(DtakoEvents.運行NO,1)' => 1])
            // ->limit(1)
            ->distinct('DtakoRows.id')
            // ->toArray()
        ;

        // dd($dr_list->count());
        // dd(arr   ay_slice($dr_list, 0, 100));
        // dump(microtime(true) - $start);

        $dr_r_ck = $this->DtakoRows->find()
            ->contain('Drivers')
            ->matching('Cars.BumonCodes.UriageJyuchuBumon.UriageJyuchuDisplay', function ($q) {
                return $q->where(['UriageJyuchuDisplay.id in' => [1]]);
            })
            // ->matching('DtakoEvents', function ($q) {
            //     return $q->where([
            //         'DtakoEvents.イベント名 ' => '積み',
            //         '得意先 is' => null
            //     ]);
            // })
            ->where(function ($exp) use ($date) {
                return $exp
                    ->gte('出庫日時', $date);
            })
            ->where([
                'DtakoRows.総走行距離 is not' => 0,
            ])

            ->join(['c' => [
                'table' => $dr_list,
                'type' => 'Left',
                'conditions' => 'c.id=DtakoRows.id'

            ]])
            ->where(
                ['c.id is not' => null]
                // function (QueryExpression $q1)use($dr_list){
                //     return $q1->Exists($dr_list);
                // }
            )

            // ->where(["DtakoRows.id in ({$dr_list})"])
            ->limit(100)
            ->order(['DtakoRows.車輌CC desc', '出庫日時 asc']);
        // dd($dr_r_ck->sql());
        // dd(microtime(true) - $start);
        $this->set(compact(['dr_r_ck']));
    }

    public function ichibandtako1redo()
    {
        $this->autoRender = false;
        $id = $this->request->getQuery('id');
        if ($id == null) {
            dump("id off");
            $request = $this->request->getQuery('redirect', ['action' => 'ichibandtakocheck']);
            return $this->redirect($request);
        } else {
            // dump("id on");
            // $this->_dtako_row_search($id, $this->request->getQuery('shaban'));
            // dd($this->request->getQuery('shaban'));

            $dd = new dryohi([$id], $this->request->getQuery('shaban'));
            // dd($dd);
            $request = $this->request->getQuery('redirect', ['action' => 'ichibandtakocheck']);
            // dump($this->request->getQuery());
            // die;
            return $this->redirect($request);
        }
    }

    public function error1EventRow()
    {
        $id = $this->request->getQuery('id');
        $this->autoRender = false;
        if ($dd = $this->DtakoEvents->get($id)) {
            // dump($dd)
            $dd->得意先 = 'error';
            $dd->備考 = 'error';
            $this->DtakoEvents->save($dd);
            $request = $this->request->getQuery('redirect', ['action' => 'ichibandtakocheck']);
            return $this->redirect($request);
            // dump($id);
        };
    }

    public function bikoDelete()
    {
        $id = $this->request->getQuery('id');
        $this->autoRender = false;
        if ($dd = $this->DtakoEvents->get($id)) {
            // dump($dd)
            $dd->備考 = null;
            $this->DtakoEvents->save($dd);
            $request = $this->request->getQuery('redirect', ['action' => 'ichibandtakocheck']);
            return $this->redirect($request);
            // dump($id);
        };
    }
    public function jogai1EventRow()
    {
        $id = $this->request->getQuery('id');
        $this->autoRender = false;
        if ($dd = $this->DtakoEvents->get($id)) {
            // dump($dd)
            $dd->備考 = '除外';
            $this->DtakoEvents->save($dd);
            $request = $this->request->getQuery('redirect', ['action' => 'ichibandtakocheck']);
            return $this->redirect($request);
            // dump($id);
        };
    }

    public function drive($id = null)
    {
        $dtakorow = $this->DtakoEvents->DtakoRows->get($id, ['contain' => ['Drivers', 'Cars']]);
        $drive = $this->DtakoEvents->find()
            ->contain('DriverPlusEvalStart')
            ->where(['運行NO' => $id])->orderAsc('開始日時')->orderAsc('終了日時')
            ->where(['イベント名 in' => ['一般道空車', '一般道実車', '高速道', '専用道', '積み', '降し']])
            ->orderAsc('開始日時')
            ->orderAsc('イベントCD')
            ->orderAsc('終了日時')
            ->map(function ($q) {
                // if($q->driver_plus_eval_start!=null){
                //     dd($q);
                // }
                $q['休息'] = $q->kyusoku->toArray();
                // if(count($q['休息'])>0){
                //     dd($q);
                // }
                $q['empty_plus_array'] = $this->DtakoEvents->DriverPlusEvalStart->find()
                    ->where(['dtako_row_id' => $q['運行NO'], 'end_time is not' => null])
                    ->where(function ($q1) use ($q) {
                        return $q1->gte('end_time', $q['終了日時']->i18nformat('yyyy-MM-dd HH:mm:ss'))->lte('start_time', $q['開始日時']->i18nformat('yyyy-MM-dd HH:mm:ss'));
                    })
                    ->orderAsc('start_time')->first();
                // if($q['empty_plus_array']!=null){
                //     // dump($q['empty_plus_array']);
                // }
                return $q;
            });
        $empty_plus = $this->DtakoEvents->DriverPlusEvalStart->find()->where(['dtako_row_id' => $id, 'end_time is' => null]);
        $empty_flg = $empty_plus->count() > 0;
        $empty_plus = $empty_plus->first();
        // dd($empty_plus_array->toArray());
        $this->set(compact('drive', 'id', 'dtakorow', 'empty_flg', 'empty_plus'));
    }

    public function checkall()
    {
        $this->loadModel('DtakoRows');
        $date = "2022-9-1";
        $dr_list = $this->DtakoRows
            ->find('list', ['valueField' => 'id'])
            ->contain('Drivers')->where(function ($q) use ($date) {
                return $q->gte('帰庫日時', $date);
            })
            ->order(['車輌CC asc', '乗務員CD1 asc', '出庫日時 asc'])
            ->toArray();
        $dt = new dryohi($dr_list);

        return $this->redirect(['action' => 'ichibandtakocheck']);
    }

    public function dtakoRowSearch()
    {
        $dd = $this->request->getQuery();
        // dump($dd);
        // die;
        // $this->_dtako_row_search($dd['id'], $dd['shaban']);
        $dt = new dryohi([$dd['id']]);
        // dd($dt);

        $request = $this->request->getQuery('redirect', ['action' => 'index']);
        return $this->redirect($request);
    }


    public function _ichiban_search($date = null, $shaban = null) //一番星から出力用データを検索
    {

        $conn = ConnectionManager::get('ichi');
        if ($shaban == null) {

            $result = $conn->newQuery()
                ->select(["format(積込年月日,'yyyyMMdd') as 積込年月日", '運転手C', "format(納入年月日,'yyyyMMdd') as 納入年月日", '車輌C+車輌H as 車輌CC', '得意先C+得意先H as 得意先CC'])
                ->from('[運転日報明細]')
                ->where(['配車K' => 0, '日報K' => 1, '請求K is not ' => 1, '車輌C not in' => ['0001', '0000']])
                ->where(['積込年月日' => $date])
                ->order(["車輌C asc", '運転手C', "積込年月日 asc", "納入年月日 asc"])
                ->execute()->fetchAll('assoc');;
        } else {
            $result = $conn->newQuery()
                ->select(["format(積込年月日,'yyyyMMdd') as 積込年月日", '運転手C', "format(納入年月日,'yyyyMMdd') as 納入年月日", '車輌C+車輌H as 車輌CC', '得意先C+得意先H as 得意先CC'])
                ->from('[運転日報明細]')
                ->where(['配車K' => 0, '日報K' => 1, '請求K is not ' => 1, '車輌C not in' => ['0001', '0000']])
                ->order(["車輌C asc", '運転手C', "積込年月日 asc", "納入年月日 asc"])
                ->where(['積込年月日' => $date, '車輌C+車輌H' => $shaban])
                ->execute()->fetchAll('assoc');;
        }

        $result3 = [];
        foreach ($result as $kk => $vv) {
            if ($vv['積込年月日'] == $vv['納入年月日']) {
                $result3[$vv['車輌CC'] . '_' . $vv['運転手C'] . '_' . $vv['積込年月日']]['当配'][$vv['納入年月日']][$vv['得意先CC']] = 1;
            } else {
                $result3[$vv['車輌CC'] . '_' . $vv['運転手C'] . '_' . $vv['積込年月日']]['複日'][$vv['納入年月日']][$vv['得意先CC']] = 1;
            }
        }

        return $result3;
    }

    function makeMonthIdle($date = null)
    {
        if ($date == null) {
            $this->redirect("/");
        }
        $date = new FrozenDate($date);
        $dtako_rows = $this->DtakoEvents->DtakoRows->find()->where(["OR" => [function (QueryExpression $st) use ($date) {
            return $st->gte("出庫日時", $date->firstOfMonth())->lt("出庫日時", $date->modify("1month")->firstOfMonth());
        }, function (QueryExpression $ed) use ($date) {

            return $ed->gte("帰庫日時", $date->firstOfMonth())->lt("帰庫日時", $date->modify("1month")->firstOfMonth());
        }]]);
        foreach ($dtako_rows as $vv) {

            Log::write('debug', __FILE__ . '/' . __FUNCTION__ . '/' . (string)__LINE__ . '/ start ' . $vv->id);
            $this->_dtakoRowSumAddTime($vv->id, "アイドリング");
            $this->_dtakoRowSumAddTime($vv->id, "休息");
            $this->_dtakoRowSumAddTime($vv->id, "運転");
            $this->_dtakoRowSumAddTime($vv->id, "休憩");
        }
        $this->redirect("/");
    }


    public function _dtakoRowSumAddTime($dtako_row_id = null, $type = null)
    {
        if ($dtako_row_id == null || $type == null) {
            return false;
        } else {
            $sum = $this->DtakoEvents->find()->select(["運行NO", "sum" => $this->DtakoEvents->find()->func()->sum("区間時間")])->where(["イベント名" => $type, "運行NO" => $dtako_row_id])->group(["運行NO"])->first();

            if ($sum == null || $sum == []) {
                // dd($sum);
                $new = $this->DtakoEvents->DtakoRowSums->newEntity(["dtako_row_id" => $dtako_row_id, "type" => $type, "value" => 0]);
            } else {
                $new = $this->DtakoEvents->DtakoRowSums->newEntity(["dtako_row_id" => $dtako_row_id, "type" => $type, "value" => $sum->sum]);
            }

            Log::write('debug', __FILE__ . '/' . __FUNCTION__ . '/' . (string)__LINE__ . '/' . $sum);
            if ($this->DtakoEvents->DtakoRowSums->save($new)) {

                // dd($new);
            } else {
            }
        }
        // $this->redirect("/");
    }


    public function _ichiban_check($array = null)
    {
        if ($array == null) {
            // dump("exit");
            return 0;
        }
        $search = [];
        foreach ($array as $kk => $vv) { //vv 当配
            $array[$kk] = substr($vv, 0, 6) . '_' . substr($vv, -6);
        }
        //     foreach ($vv as $kk1 => $vv1) { //kk1 運行日
        //         foreach ($vv1 as $kk2 => $vv2) { //kk2 得意先
        //             foreach ($vv2 as $kk3 => $vv3) { //kk2 得意先
        //                 $search[] = $kk3 . '_' . $kk2;
        //             }
        //         }
        //     }
        // }
        // dump($array);
        // die;
        // die;
        $conn = ConnectionManager::get('ichi');
        $result = $conn->newQuery()
            // ->select("*")
            // ->select(['count(distinct 得意先C) as cc','運行年月日','発地域C','着地域C','積込年月日','納入年月日','車輌C+車輌H as 車輌CC'])
            ->select(['d.得意先N', '積込年月日', '車輌C+車輌H as 車輌CC', "format(積込年月日,'yyyyMMdd') as DD", '[運転日報明細].得意先C+[運転日報明細].得意先H as 得意先CC'])
            ->from('[運転日報明細]')
            ->where(['配車K' => 0, '日報K' => 1])
            ->where(["[運転日報明細].車輌C+[運転日報明細].車輌H+'_'+format(積込年月日,'yyMMdd') in" => $array])
            ->where(['[運転日報明細].得意先C <>' => '000002'])
            ->where(function ($exp) {
                return $exp
                    // ->gte('運行年月日', $date)
                    ->notIn('車輌C', ['0001', '0000'])
                    ->notIn('請求K', [1]);
            })
            ->join([
                'table' => '得意先ﾏｽﾀ',
                'alias' => 'd',
                'type' => 'left',
                'conditions' => '[運転日報明細].得意先C+[運転日報明細].得意先H=d.得意先C+d.得意先H'
            ])
            ->where(['稼動部門 in ' => ['010', '030']])
            ->order(['積込年月日 asc', '車輌C'])
            ->execute()->fetchAll('assoc');
        // dump($result);
        // die;
        return $result;
    }

    public function files()
    {

        // $conn = ConnectionManager::get('ichi');

        $this->Authorization->skipAuthorization();
        $file = $this->DtakoEvents->newEmptyEntity();
        $this->DtakoRows = $this->fetchTable('DtakoRows');
        $this->SokudoData = $this->fetchTable('Sokudodata');
        $this->RyohiRows = $this->fetchTable('RyohiRows');
        $this->DtakoFerryRows = $this->fetchTable('DtakoFerryRows');
        $data_raws = [];
        $delete_files = [];
        $driver_noname = [];
        // $start_id = $this->DtakoRows->find()->last()->id;

        $zip = new ZipArchive();
        if ($this->request->is('post')) {
            $data_raws = $this->request->getData('name');
            $skip = $this->request->getData('skip');

            // dd($data_raws);
            // die;
            foreach ($data_raws as $data_raw) {
                // dd($data_raw->getClientMediaType());
                if ($data_raw->getClientMediaType() === "application/x-zip-compressed") {
                    $dir = new Folder();
                    $tmpDir = sys_get_temp_dir() . '/' . uniqid();
                    mkdir($tmpDir);
                    $result = $zip->open($data_raw->getStream()->getMetadata('uri'));
                    // dd($result);
                    if ($result === true) {
                        $dir = realpath($tmpDir);
                        $zip->extractTo($dir);
                        $zip->close();

                        $dir = new Folder($tmpDir);
                        // $ffiles = $dir->find('KUDGIVT.csv');
                        // $ffiles1 = $dir->find('KUDGURI.csv');
                        // $ffiles2 = $dir->find('SokudoData.csv');
                        // $ffiles3 = $dir->find('KUDGFRY.csv');
                        $make_data_from_csv =  function (string $csv_name) use ($dir, $tmpDir) {

                            $data = $dir->find($csv_name);
                            foreach ($data as $ffile) { //DtakoEvent取込準備
                                // dump($ffile);
                                $fps = fopen($tmpDir . '/' . $ffile, 'r');
                                $i = 0;
                                $data_r = [];
                                $d_flag = false;
                                while (($fp = fgetcsv($fps, 0, ",")) !== FALSE) { //ファイル最終行まで読み込み
                                    $data_r[++$i] = mb_convert_encoding($fp, 'UTF-8', ['SJIS-win', 'SJIS', 'UTF-8']);  // エンコード
                                }
                            }
                            return $data_r;
                        };

                        $DtakoRows = new Enter_data('KUDGURI.csv', $tmpDir);
                        // dd($DtakoRows->ids);
                        $DtakoEvents = new Enter_data('KUDGIVT.csv', $tmpDir);
                        $SokudoData = new Enter_data('SokudoData.csv', $tmpDir);
                        $KUDGFRY = new Enter_data('KUDGFRY.csv', $tmpDir);
                    }
                }
            }
            // dump("error");
            // goto skipryohi;
            if ($DtakoRows->is_error() or $DtakoEvents->is_error() or $SokudoData->is_error() or $KUDGFRY->is_error()) { //旅費計算、旅費IDセット
                // dd("error occered");
                $this->Flash->error(__('The dtako event could not be saved. Please, try again.'));
            } else {
                // dd("test");
                $time_start = microtime(true);
                // dd($DtakoRows->ids);
                if (!$skip) { //test時一番星連携が邪魔するため、テスト時はスキップ
                    $dt = new dryohi($DtakoRows->ids);
                }
                $this->Flash->success(microtime(true) - $time_start);
                $sub = $this->RyohiRows->find()->where(['旅費運行NO in' => $DtakoRows->ids]);
                if ($sub->count() > 0) {
                    $this->RyohiRows->RyohiIdSet($sub->find('list'));
                }
            }

            // skipryohi:
            $this->Flash->success(__('The dtako event ' . $data_raw->getClientFilename() . ' has been saved.'));

            $request = $this->request->getQuery('redirect', '/pages');
            return $this->redirect($request);
            // } else {
            //     $this->Flash->error(__('The dtako event could not be saved. Please, try again.'));
        }

        // $file = [];
        // if (isset($data_R)) {

        //     $this->set(compact('data_R', 'file'));
        // } else {
        // $this->set(compact('file'));
        // }F
    }


    /**
     * View method
     *
     * @param string|null $id Dtako Event id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $this->loadModel('DtakoRows');

        $conn = ConnectionManager::get('ichi');
        $dtakoEv = $this->DtakoRows->get($id, ['contain' => [
            'DtakoEvents' => function (Query $q) {
                return $q->where(
                    [' DtakoEvents.イベント名 in ' =>
                    ['運行開始', '運転', '休憩', '休息', '積み', '降し', '運行終了']]
                )->order('開始日時 asc');
            },
            'DtakoEvents.DtakoEventsDetails',
            'Drivers',
        ]]);
        $ch_tsumi = [];
        foreach ($dtakoEv->dtako_events as $vv) {
            $st1 = $vv->イベント名 == '積み' && $vv->得意先 == null;
            $st2 = false;
            if ($vv->has("dtako_events_detail")) {
                if ($vv->dtako_events_detail->備考 == "保留") {
                    $st2 = true;
                }
            }
            // if($st1){

            //     dump("st",$st1,$st2);
            // }
            if ($st1 && $st2 == false) {
                // if ($st1 ) {
                $vv['卸候補'] = null;

                $ch_tsumi[] = ['積み' => $vv, '卸し' => null];
            }
            if ($vv->イベント名 == '降し' and count($ch_tsumi) > 0) {
                if ($ch_tsumi[array_key_last($ch_tsumi)]['卸し'] == null) {
                    $ch_tsumi[array_key_last($ch_tsumi)]['卸し'] = $vv;
                } else {
                    if ($vv->得意先 == null) {

                        $ch_tsumi[] = ['積み' => null, '卸し' => $vv];
                    }
                }
            }
        }

        $dtako_last = $this->DtakoRows->find()
            ->where(['乗務員CD1' => $dtakoEv->乗務員CD1, '車輌CC' => $dtakoEv->車輌CC, '乗務員CD1' => $dtakoEv->乗務員CD1])
            ->where(function (QueryExpression $q) use ($dtakoEv) {
                return $q->lt('出庫日時', $dtakoEv->出庫日時);
            })
            ->contain('DtakoEvents')
            ->order(['出庫日時 desc'])->first();

        $dtako_next = $this->DtakoRows->find()
            ->where(['乗務員CD1' => $dtakoEv->乗務員CD1, '車輌CC' => $dtakoEv->車輌CC, '対象乗務員区分' => 1])
            ->where(function (QueryExpression $q) use ($dtakoEv) {
                return $q->gt('出庫日時', $dtakoEv->出庫日時);
            })
            ->contain('DtakoEvents')->order(['出庫日時 asc'])->first();
        // $is_next_oroshi_first=

        function is_next_oroshi_fst(DtakoRow $dtako_next = null)
        {
            if ($dtako_next == null) {
                return false;
            }
            foreach ($dtako_next->dtako_events as $vv) {
                if (!in_array($vv['得意先'], ['除外'])) {
                    if ($vv['イベント名'] == '降し') {
                        // dd($vv);
                        return $vv;
                    }
                    if ($vv['イベント名'] == '積み') {
                        return false;
                    }
                }
            }
            return false;
        }

        function is_last_oroshi_last(DtakoRow $dtako_last = null)
        {
            $tmp = null;
            $tmp_ar = [];
            if ($dtako_last == null) {
                return false;
            }
            foreach ($dtako_last->dtako_events as $vv) {
                if (!in_array($vv['得意先'], ['除外', 'error'])) {
                    if ($vv['イベント名'] == '降し') {
                        $tmp = false;
                        $tmp_ar = [];
                    }
                    if ($vv['イベント名'] == '積み') {
                        $tmp_ar = $vv;
                        $tmp = true;
                    }
                }
            }
            if ($tmp) {
                return $tmp_ar;
            } else {
                return false;
            }
        }
        $is_next_oroshi_fst = is_next_oroshi_fst($dtako_next);
        $is_last_oroshi_last = is_last_oroshi_last($dtako_last);
        // dd($is_next_oroshi_fst);
        // dump($dtakoEv->帰庫日時->format('Y-m-d H:i:s'));
        $kiko = $dtakoEv->帰庫日時->format('Y-m-d H:i:s');
        $shuko = $dtakoEv->運行日->format('Y-m-d H:i:s');
        // die;


        // dd(substr($id,0,22));
        // dd($id);
        /**
         * TODO: 乗務員都合のETC集計の元が、DtakoEtcになっているため、EtcMeisaiから出力する
         */
        $detc = $this->DtakoEvents->DtakoRows->DtakoEtc->find()->where(['unko_no' => substr($id, 0, 22)])
            ->contain(['DtakoUriageKeihi'])
            ->where(['etc' => 1])
            ->orderAsc('DtakoEtc.start_datetime')
            ->orderAsc('DtakoEtc.end_datetime')
            ->all()
            ->map(function ($q) {

                $tmp =
                    $this->DtakoEvents->find()->where(['運行NO' => $q->unko_no . '1', 'イベント名' => '積み'])
                    ->where(function (QueryExpression $st) use ($q) {
                        return $st->lt('開始日時', $q->start_datetime->i18nformat('yyyy-MM-dd HH:mm:ss'));
                    })
                    ->select(['srch_id', 'st' => "CONCAT(Date_format(開始日時,'%m/%d %H:%i'),' ',イベント名,' ',開始市町村名)"])
                    ->where(['得意先 is not' => null])
                    ->toArray();
                $q->tsumi = array_combine(array_column($tmp, 'srch_id'), array_column($tmp, 'st'));
                // dd($q->tsumi);
                return $q;
            });
        $dd_uriage_list_row = $this->DtakoEvents->DtakoRows->DtakoUriageKeihi->find()
            ->where(['dtako_row_id' => $id, 'keihi_c' => 0])->toArray();
        if ($dd_uriage_list_row != null) {

            $dd_uriage_list = array_column($dd_uriage_list_row, 'srch_id');
        } else {

            $dd_uriage_list = [$id];
        }
        // dd('test');
        // dd($dd_uriage_list);

        $ddetc_srch_count = $this->DtakoEvents->DtakoRows->EtcMeisai->find()->where(['EtcMeisai.dtako_row_id' => $id])->notMatching('DtakoUriageKeihi')->count();
        $ddetc = $this->DtakoEvents->DtakoRows->EtcMeisai->find()->where(['EtcMeisai.dtako_row_id' => $id])
            ->contain(['DtakoUriageKeihi'])
            ->orderAsc('date_to')
            ->orderAsc('date_fr')
            ->all()
            ->map(function ($q) use ($dd_uriage_list) { //unko_no 毎、etc_meisaiをループ


                /**
                 * @var iterable<\app\Model\Entity\DtakoEvent> $tm_tokui_array
                 * 
                 */
                $tm_tokui_array = $this->DtakoEvents->find()->where(['運行NO' => $q->dtako_row_id])
                    ->where(['イベント名 in' => ['運行開始', '運転', '積み', '降し', '休憩', '休息', "運行終了"]])
                    ->where(function (QueryExpression $st) use ($q) { //etcmeisai終了日時より後のイベントを抽出、開始日時isNullable
                        return $st->lt('開始日時', $q->date_to->i18nformat('yyyy-MM-dd HH:mm:ss'));
                    })->orderDesc('開始日時')->toArray();

                // dump($q);
                // dump($tm_tokui_array);
                //var itable< \app\Model\Entity\DtakoEvent> $tm_tokui_array
                if ($tm_tokui_array[0]->得意先 != null) { //etc終了日時より前のイベントに得意先が設定されていた場合、
                    /** 
                     * @var string $tm_tokui etc終了日時より前のイベントに設定されている得意先 first */
                    $tm_tokui = $tm_tokui_array[0]->得意先;
                    // dd($tm_tokui);
                    $tm_srch_id = null;
                    /**空の積みを作成 */
                    $q->tsumi = [];
                    foreach ($tm_tokui_array as $is => $vvs1) { //etcmeisai終了日時より後のイベントを総当たり
                        if ($vvs1->得意先 == $tm_tokui) {
                            if (in_array($vvs1->srch_id, $dd_uriage_list)) {
                                $inputKey = $tm_tokui_array[$is]->srch_id;
                                // $q->tsumi[$inputKey] = $tm_tokui_array[$is]->開始日時->i18nformat('MM/dd HH:mm') . ' ' . $tm_tokui_array[$is]->イベント名 . ' ' . $tm_tokui_array[$is]->開始市町村名;
                                $q->tsumi[$inputKey] = $vvs1->開始日時->i18nformat('MM/dd HH:mm') . ' ' . $vvs1->イベント名 . ' ' . $vvs1->開始市町村名;
                            }
                            if ($vvs1->イベント名 == '運行開始') {
                                $tm_id = $this->DtakoEvents->DtakoRows->find()->where(['乗務員CD1' => $vvs1->乗務員CD1, '車輌CC' => $vvs1->車輌CC])
                                    ->where(function (QueryExpression $qq1) use ($vvs1) {
                                        return $qq1->lte('帰庫日時', $vvs1->開始日時->i18nformat('yyyy-MM-dd HH:mm'));
                                    })->orderDesc('出庫日時')->first()->id;
                                // dump($tm_tokui_array);
                                // dd($tm_id);
                                if ($tm_id != null) {

                                    $tm_tokui_array_add = $this->DtakoEvents->find()->where(['運行NO' => $tm_id])
                                        ->where(['イベント名 in' => ['運行開始', '運転', '積み', '降し', '休憩', '休息', '運行終了']])
                                        ->where(function (QueryExpression $st) use ($q) {
                                            return $st->lt('開始日時', $q->date_to->i18nformat('yyyy-MM-dd HH:mm:ss'));
                                        })->orderDesc('開始日時')->toArray();

                                    if ($tm_tokui_array_add[0]->得意先 == $tm_tokui) {


                                        foreach ($tm_tokui_array_add as $is2 => $vvs2) {
                                            if ($vvs2->得意先 == $tm_tokui) {
                                            } else {
                                                // if(is_null($tm_tokui_array_add[$is]->開始日時)){
                                                //     dd($tm_tokui_array_add[$is2]);
                                                // }
                                                // $q->tsumi[$tm_tokui_array_add[$is]->srch_id] = $tm_tokui_array_add[$is]->開始日時->i18nformat('MM/dd HH:mm') . ' ' . $tm_tokui_array_add[$is]->イベント名 . ' ' . $tm_tokui_array_add[$is]->開始市町村名;
                                                // $q->tsumi[$tm_tokui_array_add[$is2]->srch_id] = $tm_tokui_array_add[$is2]->開始日時->i18nformat('MM/dd HH:mm') . ' ' . $tm_tokui_array_add[$is]->イベント名 . ' ' . $tm_tokui_array_add[$is]->開始市町村名;
                                                $q->tsumi[$vvs2->srch_id] = $vvs2->開始日時->i18nformat('MM/dd HH:mm') . ' ' . $vvs2->イベント名 . ' ' . $vvs2->開始市町村名;
                                                // $q->tsumi[$tm_tokui_array_add[$is2]->srch_id] = $tm_tokui_array_add[$is2]->開始日時->i18nformat('MM/dd HH:mm') . ' ' . $tm_tokui_array_add[$is]->イベント名 . ' ' . $tm_tokui_array_add[$is]->開始市町村名;
                                                // dump($tm_tokui_array);
                                                // dd($tm_tokui_array_add);
                                                break 2;
                                            }
                                        }
                                        // dump($tm_tokui);
                                        // array_push($tm_tokui_array, $tm_tokui_array_add);
                                    }
                                }
                                // dd($vvs1);
                            }
                        } else {
                            // dump('fin');
                            // dump($tm_tokui_array[$is - 1]);
                            $q->tsumi[$tm_tokui_array[$is - 1]->srch_id] = $tm_tokui_array[$is - 1]->開始日時->i18nformat('MM/dd HH:mm') . ' ' . $tm_tokui_array[$is - 1]->イベント名 . ' ' . $tm_tokui_array[$is - 1]->開始市町村名;
                            break;
                        }
                    }

                    // if($tm_toku)
                    // dd($q);

                    // $tmp =  $this->DtakoEvents->find()

                    //     ->where(['運行NO in ' =>$tmp_ids, 'イベント名' => '積み'])->where(function (QueryExpression $st) use ($q) {
                    //         return $st->lt('開始日時', $q->date_to->i18nformat('yyyy-MM-dd HH:mm:ss'));
                    //     })
                    //     ->select(['srch_id', 'st' => "CONCAT(Date_format(開始日時,'%m/%d %H:%i'),' ',イベント名,' ',開始市町村名)"])
                    //     ->where(['得意先 is not' => null])
                    //     ->toArray();
                    // $q->tsumi = array_combine(array_column($tmp, 'srch_id'), array_column($tmp, 'st'));
                    $q->jisha = 1;
                } else {
                    if ($q->dtako_uriage_keihi != null and !in_array($q->dtako_uriage_keihi->keihi_c, [3, 4])) {
                        // dd($q);
                        $this->DtakoEvents->DtakoRows->DtakoUriageKeihi->delete($q->dtako_uriage_keihi);
                        $q->dtako_uriage_keihi = null;
                    }
                    $q->jisha = 0;
                    $q->tsumi = [3 => '配車都合', 4 => '乗務員都合'];
                }
                // dd($q->tsumi);
                return $q;
            });

        $dferry =   $this->DtakoEvents->DtakoRows->DtakoFerryRows->find()->where(['運行NO' => $id])->orderAsc('開始日時');
        // dd($dferry->toArray());
        /**
         * 一番星リスト
         *  @var \Cake\Database\Query $ichi_r  */
        $ichi_r = $conn->newQuery()
            ->select([
                '車輌C+車輌H as 車輌CC',
                '積込年月日',
                '運行年月日',
                '納入年月日',
                '発地N',
                '着地N',
                '品名N',
                'd.得意先N',
                'd.得意先C+d.得意先H as 得意先CC',
                '備考',
                '備考2',
                '請求K',
                'e.社員N',
                '運転手C',
                '金額',
                '値引',
                '割増',
                '実費',
                '入力担当N' => 'f.社員N'
            ])->from(['運転日報明細'])
            ->where(['配車K' => 0, '日報K' => 1, '請求K in ' => [0, 2], '運転手C' => $dtakoEv->乗務員CD1, '車輌C+車輌H' => $dtakoEv->車輌CC])
            ->where(function ($e) use ($kiko, $shuko) {
                return $e
                    ->gte('積込年月日', $shuko)
                    ->lte('積込年月日', $kiko);
            })
            ->where(['[運転日報明細].得意先C <>' => '000002'])
            // ->where(['cc' => 1])
            ->join([
                'table' => '得意先ﾏｽﾀ',
                'alias' => 'd',
                'type' => 'left',
                'conditions' => 'd.得意先C+d.得意先H=[運転日報明細].得意先C+[運転日報明細].得意先H'
            ])
            ->join([
                'table' => '社員ﾏｽﾀ',
                'alias' => 'e',
                'type' => 'left',
                'conditions' => 'e.社員C=[運転日報明細].運転手C'
            ])
            ->join([
                'table' => '社員ﾏｽﾀ',
                'alias' => 'f',
                'type' => 'left',
                'conditions' => 'f.社員C=[運転日報明細].入力担当C'
            ])
            ->order(['積込年月日 asc', '金額 asc']);


        $keihi = $conn->newQuery()
            ->select([
                '車輌C+車輌H as 車輌CC',
                '運行年月日',
                '計上年月日',
                '数量',
                '単価',
                '金額',
                '備考',
                '経費N' => 'f.経費N',
                'f.経費C',
                'd.未払先N',
            ])->from(['経費明細'])
            ->where(['運転手C' => $dtakoEv->乗務員CD1, '車輌C+車輌H' => $dtakoEv->車輌CC])
            ->where(function (QueryExpression $d1) use ($dtakoEv) {
                return $d1->lte('運行年月日', $dtakoEv->帰庫日時->i18nFormat('yyyy-MM-dd'))->gte('運行年月日', $dtakoEv->出庫日時->i18nFormat('yyyy-MM-dd'));
            })
            ->join([
                'table' => '経費ﾏｽﾀ',
                'alias' => 'f',
                'type' => 'left',
                'conditions' => 'f.経費C=[経費明細].経費C'
            ])
            ->join([
                'table' => '未払先ﾏｽﾀ',
                'alias' => 'd',
                'type' => 'left',
                'conditions' => ['d.未払先C=[経費明細].未払先C', 'd.未払先H=[経費明細].未払先H']
            ])
            ->orderAsc('運行年月日')
            ->orderAsc('計上年月日')
            ->execute()->fetchAll('obj');
        // dd($keihi);
        $dtako_row = $this->DtakoEvents->DtakoRows->get($id, ['contain' => ['RyohiRows', 'RyohiRowPrechecks', "EtcMeisaiAftOroshi"]]);

        $d_uriage_list_row = $this->DtakoEvents->DtakoRows->DtakoUriageKeihi->find()
            ->leftJoinWith('DtakoUriageKeihiChildIn')
            ->where(['OR' =>
            [
                'DtakoUriageKeihiChildIn.dtako_row_id ' => $id,
                'DtakoUriageKeihi.dtako_row_id ' => $id,
            ]])
            // ->where(['DtakoUriageKeihi.dtako_row_id' => $id])
            ->where(['DtakoUriageKeihi.keihi_c' => 0])
            ->distinct('DtakoUriageKeihi.srch_id')
            ->toArray();
        // dd($d_uriage_list_row);
        if ($d_uriage_list_row != null) {

            $d_uriage_list = array_column($d_uriage_list_row, 'srch_id');
        } else {
            $d_uriage_list = [$id];
        }

        $d_uriage = $this->DtakoEvents->DtakoRows->DtakoUriageKeihi->find()
            // ->where(['DtakoUriageKeihi.dtako_row_id' => $id])
            ->where(['DtakoUriageKeihi.srch_id in' => $d_uriage_list])
            ->where(['DtakoUriageKeihi.keihi_c' => 0])
            // ->matching('DtakoUriageKeihiChild',function(Query $q)use($id){
            //     return $q->where(['DtakoUriageKeihiChild.dtako_row_id'=>$id]);
            // })
            ->contain(['DtakoUriageKeihiChild'])
            ->orderAsc('DtakoUriageKeihi.keihi_c')
            ->orderAsc('DtakoUriageKeihi.datetime');

        $fuel_tanka_tble = TableRegistry::getTableLocator()->get('DtakoFuelTanka');
        $fuel_tanka = $fuel_tanka_tble->find()->where(function (QueryExpression $q) use ($dtakoEv) {
            return $q->lte('month_int', (int)$dtakoEv->帰庫日時->i18nFormat('yyMM'));
        })->orderDesc('month_int')->first();

        $tsumi_oroshi = $this->DtakoEvents->find()->where(['イベント名 in' => ['積み', '降し'], '運行NO' => $id])->orderAsc('開始日時')->toArray();


        // dd($tsumi_oroshi->toArray());
        // dd($fuel_tanka);
        $this->set(compact(
            'dtakoEv',
            'ichi_r',
            'ch_tsumi',
            'dtako_last',
            'dtako_next',
            'is_next_oroshi_fst',
            'is_last_oroshi_last',
            'dtako_row',
            'keihi',
            'detc',
            'ddetc',
            'd_uriage',
            'fuel_tanka',
            'dferry',
            'tsumi_oroshi',
            'ddetc_srch_count',
        ));
    }


    public function map2(string $id = null)
    {
        // dd($this->request->getQuery());
        // dd($id);
        if ($id == null) {
            $this->redirect(['controller' => 'Pages', 'action' => 'home']);
        } else {
            $DtakoEvent = $this->DtakoEvents->find()->where(["運行NO" => $id])->where(["イベント名 in" => ["積み", "降し"]]);
            // dd($DtakoEvent->toArray());
            $this->set(compact('DtakoEvent'));
        }


        # code...
    }
    public function map(string $id = null)
    {
        // dd($this->request->getQuery());
        // dd($id);
        if ($id == null) {
            $this->redirect(['controller' => 'Pages', 'action' => 'home']);
        } else {
            $DtakoEvent = $this->DtakoEvents->get((int)$id);
            $this->set(compact('DtakoEvent'));
        }


        # code...
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $dtakoEvent = $this->DtakoEvents->newEmptyEntity();
        if ($this->request->is('post')) {
            $dtakoEvent = $this->DtakoEvents->patchEntity($dtakoEvent, $this->request->getData());
            if ($this->DtakoEvents->save($dtakoEvent)) {
                $this->Flash->success(__('The dtako event has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The dtako event could not be saved. Please, try again.'));
        }
        $this->set(compact('dtakoEvent'));
    }

    /**
     * Edit method
     *
     * @param string|null $id Dtako Event id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        $dtakoEvent = $this->DtakoEvents->get($id, [
            'contain' => [],
        ]);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $dtakoEvent = $this->DtakoEvents->patchEntity($dtakoEvent, $this->request->getData());
            if ($this->DtakoEvents->save($dtakoEvent)) {
                $this->Flash->success(__('The dtako event has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The dtako event could not be saved. Please, try again.'));
        }
        $this->set(compact('dtakoEvent'));
    }

    /**
     * Delete method
     *
     * @param string|null $id Dtako Event id.
     * @return \Cake\Http\Response|null|void Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $dtakoEvent = $this->DtakoEvents->get($id);
        if ($this->DtakoEvents->delete($dtakoEvent)) {
            $this->Flash->success(__('The dtako event has been deleted.'));
        } else {
            $this->Flash->error(__('The dtako event could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }


    // public function autoload()
    // {

    //     $skip = $this->request->getData('skip', false);
    //     $test = $this->request->getData('test', null);
    //     $this->_autoload($skip, $test);
    //     $this->redirect('/');
    //     # code...
    // }

    // {
    // $this->redirect('/');
    // }
    // public function autoloadtest($skip = false, $test = null)
    public function autoload($skip = false, $test = null)
    {
        // $this->redirect('/');
        $skip = $this->request->getData('skip', false);
        $test = $this->request->getData('test', null);

        // $this->Flash->success('start');
        if ($test != null) {
            $test_s = 'test/';
            $test_r = '/test';
        } else {
            $test_s = null;
        }
        $dir = '/var/www/html/files/dtako_csv/' . $test_s;

        // $this->Flash->success('start1');
        // $this->Flash->success('start1');
        // $this->Flash->success('start1');
        $ddir = glob($dir . '*.csv');

        // $this->Flash->success('start2');
        $search = [
            "/var/www/html/files/dtako_csv/" . $test_s . "KUDGFRY.csv",
            "/var/www/html/files/dtako_csv/" . $test_s . "KUDGFUL.csv",
            "/var/www/html/files/dtako_csv/" . $test_s . "KUDGIVT.csv",
            "/var/www/html/files/dtako_csv/" . $test_s . "KUDGURI.csv",
            "/var/www/html/files/dtako_csv/" . $test_s . "SokudoData.csv",
        ];
        // dd($dir);
        // $this->Flash->success('start3');
        // dd($search);
        for ($i = 1; $i < 10; $i++) {
            if (array_intersect($search, $ddir) == null) {
                // $this->Flash->success('null');
                continue;
            }
            // dump('test');
            // $this->Flash->success('autoload1 bf');
            $this->_autoload($skip, $test);
            // $this->Flash->success('autoload1');
        }
        // $this->Flash->success('start4');

        $file = $this->request->getData('file');
        Log::debug(var_export($this->request->getData('file'), true));
        // dump('test2');
        if ($file != null) {

            $zip = new ZipArchive();
            // if ($this->request->is('post')) {

            // dd($data_raws);
            // die;
            // dump($file);
            // dump('test');
            // dump($file);
            $zz = 0;
            if (!is_array($file)) {

                Log::debug('is not array ' . __LINE__ . __FILE__);
            }
            foreach ($file as $data_raw) {
                Log::debug(__FILE__ . __LINE__);
                // dd($data_raw->getClientMediaType());
                if ($data_raw->getClientMediaType() === "application/x-zip-compressed") {
                    // $dir = new Folder();
                    // $tmpDir = sys_get_temp_dir() . '/' . uniqid();
                    // mkdir($tmpDir);
                    $result = $zip->open($data_raw->getStream()->getMetadata('uri'));
                    // $dir = realpath($dir);
                    if ($zip->extractTo($dir)) {
                        do {

                            $ddir = glob($dir . '*.csv');
                            usleep(3000);
                            // Log::debug("".__LINE__);
                            // Log::debug(array_intersect($search, $ddir)==null?"SS":var_export(array_intersect($search, $ddir)));
                            // $this->log("" . __LINE__);

                        } while (
                            array_intersect($search, $ddir) == null
                        );
                    }
                    $zip->close();
                    // usleep(30000);

                    // $this->Flash->success('zip');
                    // sleep(1);
                    for ($i = 1; $i < 10; $i++) {
                        if (array_intersect($search, $ddir) == null) {
                            $ddir = glob($dir . '*.csv');

                            continue;
                        }
                        // dump('test');
                        Log::debug(__FILE__ . __LINE__);

                        $this->_autoload($skip, $test);
                        // $this->Flash->success('autoload12');
                        $zz = 1;
                    }

                    if ($zz) {
                        $this->Flash->success('zip');
                    } else {
                        $this->Flash->error('fail');
                    };
                } else {
                    Log::debug('not zip');
                }
            }
            // }
        }
        $this->_clean();
        Log::debug(__FILE__ . __LINE__);
        // $this->Flash->success('test');
        Log::debug(__FILE__ . __LINE__ . " geocode start");
        // $this->_setGeoCode();
        Log::debug(__FILE__ . __LINE__ . " geocode end");
        if ($this->request->getData('api')) {
            Log::debug(__FILE__ . __LINE__ . ' false');
            $this->autoRender = false;
        } else {

            $this->redirect($this->request->getQuery('redirect', '/'), 307);
        }
        # code...
    }

    public function clean()
    {
        $this->_clean();
        # code...
    }

    public function _clean()
    {
        $file = $this->request->getData('file');

        $dir = '/var/www/html/files/ichizip/';

        $ddir = glob($dir . '/*/*/*.CS$');

        foreach ($ddir as $vv) {

            unlink($vv);
        }
        $ddir = glob($dir . '/*/*/');
        foreach ($ddir as $vv) {
            $files = array_diff(scandir($vv), array('.', '..'));
            if (empty($files)) {

                rmdir($vv);
            }
        }
        // dd($ddir2);
        # code...
    }

    public function _autoload($skip = false, $test = null)
    {
        $skip = $this->request->getData('skip', false);
        $test = $this->request->getData('test', null);
        if ($test != null) {
            $test = 'test/';
            $test_r = '/test';
        }
        if (!isset($test_r)) {
            $test_r = null;
        }
        // dump($skip);
        // dump($test);
        // $this->Flash->success($skip);
        $csv_URI = new \SplFileInfo('/var/www/html/files/dtako_csv/' . $test . 'KUDGURI.csv');
        $csv_FRY = new \SplFileInfo('/var/www/html/files/dtako_csv/' . $test . 'KUDGFRY.csv');
        $csv_IVT = new \SplFileInfo('/var/www/html/files/dtako_csv/' . $test . 'KUDGIVT.csv');
        $csv_Sokudo = new \SplFileInfo('/var/www/html/files/dtako_csv/' . $test . 'SokudoData.csv');
        $this->RyohiRows = $this->fetchTable('RyohiRows');
        $idsss = [];
        foreach ([$csv_IVT, $csv_URI, $csv_FRY, $csv_Sokudo] as $vv) {
            Log::debug(__FILE__ . __LINE__);
            Log::debug($vv->getFilename());
            if ($vv->isWritable()) {
                // dump($vv);\\
                $filename_r = str_replace("dtako_csv/" . $test, "dtako_csv/" . $test . "tmp/", $vv->getPathname());
                $foldername_r = str_replace("dtako_csv" . $test_r, "dtako_csv" . $test_r . "/tmp", $vv->getPath());
                Log::debug(__FILE__ . __LINE__);
                Log::debug($vv->getFilename() . " start inport");
                rename($vv->getPathname(), $filename_r);
                $dd = new Enter_data($vv->getFilename(), $foldername_r);
                Log::debug(__FILE__ . __LINE__);
                Log::debug($vv->getFilename());
                if (isset($csv_URI) && $vv->getFilename() == "KUDGURI.csv") {
                    $idsss = $dd->ids;
                    foreach ($dd->ids as $id1) {
                        $this->DtakoEvents->DtakoRows->DtakoFerryRows->deleteAll(['運行NO' => $id1]);
                        $this->DtakoEvents->DtakoRows->DtakoKeihi->deleteAll(['dtako_row_id' => $id1]);
                        $this->DtakoEvents->DtakoRows->DtakoFuel->deleteAll(['dtako_row_id' => $id1]);
                        $this->DtakoEvents->DtakoRows->DtakoEtc->deleteAll(['unko_no' => substr($id1, 0, -1)]);

                        $this->log(__FILE__ . ":" . __LINE__ . " executed");
                        try {
                            Log::debug(var_export($id1, true));
                        } catch (Exception $e) {
                            Log::error(var_export($e, true));
                        }
                        // Log::debug('dryohi_ids',$dd->ids);
                        // $uniq = array_unique($dd->ids);
                        if ($skip == false) {

                            $this->DtakoEvents->DtakoRows->RyohiRowPrechecks->setStatus($id1);
                            $dt = new dryohi([$id1]);
                        }
                    }
                }
                Log::debug($vv->getFilename());
                // new Enter_data($vv->getFilename(),$vv->getPath());

                if ($skip == false && isset($csv_URI) && $vv->getFilename() == "KUDGIVT.csv") { //test時一番星連携が邪魔するため、テスト時はスキップ

                    // $dt = new dryohi($dd->ids);
                    // dump($dd->ids);
                    Log::debug(__FILE__ . __LINE__ . json_encode($dd->ids));
                    $list = $this->RyohiRows->find('list', ['valueField' => 'id'])->where(['運行NO in' => $dd->ids])->toArray();
                    // dd($list);
                    // dd($vv);
                    // dd($dd);
                    // if()
                    if ($list != null) {

                        Log::debug(__FILE__ . __LINE__);
                        $this->RyohiRows->RyohiIdSet($list);
                        Log::debug(__FILE__ . __LINE__);
                    }
                    // if ($skip == false && isset($csv_URI) && $vv->getFilename() == "KUDGURI.csv") { //test時一番星連携が邪魔するため、テスト時はスキップ
                    // }
                }
                // if($vv->getFilename() == "KUDGURI.csv"){
                //     dd($dd->ids);
                // }
            } else {

                Log::debug($vv->getFilename() . ' none');
            }
            Log::debug(__FILE__ . __LINE__);
            Log::debug($vv->getFilename());
        }
        Log::debug(__FILE__ . __LINE__);
        foreach (['DtakoKeihi', 'DtakoFuel', 'DtakoEtc'] as $vv) {
            $$vv = $this->fetchTable($vv);
            Log::debug(__FILE__ . __LINE__ . ":" . $vv);
            $$vv->importCsv($test);
        }

        Log::debug(__FILE__ . __LINE__);
        // foreach($idsss as $vv){
        //     $this->
        // }
        $dtakocon = new \App\Controller\DtakoRowsController();

        // dd($idsss);
        // dump($idsss);
        // $this->Flash->success($vv);
        // dd($dtakocon);
        foreach ($idsss as $vv) {

            $dtakorowId = $this->DtakoEvents->DtakoRows->get($vv);
            // dd($dtakorowId);
            Log::debug(__FILE__ . __LINE__ . " id:" . $vv . " 出庫日時:" . $dtakorowId->出庫日時->i18nFormat('yyyy-MM-dd'));
            if ((int)date('ymd', strtotime('-2 month ')) < (int)date("ymd", strtotime($dtakorowId->出庫日時->i18nFormat('yyyy-MM-dd')))) { // ２か月以内の分だけ、CSV吐き出し

                // dd($vv);
                Log::debug(__FILE__ . __LINE__ . " id:" . $vv . "making csv");
                $dtakocon->_makecsv($vv); /// 改修時、CSV作成を一時停止するため、コメントアウト
            } else {
                Log::debug(__FILE__ . __LINE__ . " id:" . $vv . " making csv skip");
                // dd('fals');
            }
            $this->_dtakoRowSumAddTime($vv, "アイドリング");
            $this->_dtakoRowSumAddTime($vv, "休憩");
            $this->_dtakoRowSumAddTime($vv, "休息");
            $this->_dtakoRowSumAddTime($vv, "運転");
        }
        # code...
    }

    public function autooutput()
    {
        $conn = ConnectionManager::get('ichi');
        $date = $this->request->getData('date', null);
        $date = new FrozenDate($date);
        $keihi = $conn->newQuery()
            ->select([
                '運行年月日' => '運行年月日',
                '車輌CC' => '車輌C+車輌H',
                '未払先CC' => '未払先C+未払先H',
                '経費C' => '経費C',
                'sum' => $conn->newQuery()->func()->sum('金額')
            ])
            ->from(['経費明細'])
            ->where(function (QueryExpression $q) use ($date) {
                return $q->gte('運行年月日', $date->subDay(60)->i18nformat('yyyy-MM-dd'))
                    ->gte('運行年月日', '2022-9-1');
            })->group([
                '運行年月日',
                '車輌C+車輌H',
                '未払先C+未払先H',
                '経費C'
            ])
            ->execute()->fetchAll('assoc');
        dd($keihi);
    }
    public function makeoutput($id = null)
    {
        $test = $this->request->getData('test', null);
        // dd($id);
        if ($id == null) {
            return false;
        }
        if (!is_array($id)) {
            $id = [$id];
        }

        $folder_path = "/var/www/html/files/dtako_csv/test/00000000";
        // $folder_path = '/path/to/dir';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folder_path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir() === true) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($folder_path);
        mkdir($folder_path);
        chmod($folder_path, 0777);

        $DtakoRows = $this->DtakoEvents->DtakoRows->find()->where(['id in' => $id])->contain([
            // 'DtakoFuel',
            'DtakoKeihi',
            'DtakoFerryRows',
            'DtakoEtc',
            'RyohiRows'
        ])->orderAsc('読取日');


        $ss = '"';


        function ichinum($num = 0)
        {
            if ($num == 0) {
                return 0;
            } else {
                // dd((($num/60)-intdiv($num,60))*0.6);
                return round((float)intdiv($num, 60) + (($num / 60) - intdiv($num, 60)) * 0.6, 2);
            }
        };

        foreach ($DtakoRows as $DtakoRow) {
            if (!is_dir($folder_path . '/' . $DtakoRow->読取日->i18nformat('yyyyMMdd'))) {
                mkdir($folder_path . '/' . $DtakoRow->読取日->i18nformat('yyyyMMdd'), 0777, true);
                chmod($folder_path . '/' . $DtakoRow->読取日->i18nformat('yyyyMMdd'), 0777);
            }
            $data = [];
            $data[] = [
                $ss . "0" . $ss,
                $ss . $DtakoRow->出庫日時->i18nformat('yyyy/MM/dd') . $ss,
                $ss . $DtakoRow->出庫日時->i18nformat('HH:mm') . $ss,
                $ss . $DtakoRow->帰庫日時->i18nformat('yyyy/MM/dd') . $ss,
                $ss . $DtakoRow->帰庫日時->i18nformat('HH:mm') . $ss,
                $ss . (substr($DtakoRow->車輌CC, 0, 4)) . $ss,
                $ss . (substr($DtakoRow->車輌CC, 4, 2)) . $ss,
                $ss . $DtakoRow->乗務員CD1 . $ss,
                $ss . $ss,
                $ss . $ss,
                (int)$DtakoRow->出庫メーター,
                (int)$DtakoRow->帰庫メーター,
                (int)$DtakoRow->総走行距離,
                (int)$DtakoRow->状態１距離,
                ichinum($DtakoRow->作業１時間),
                ichinum($DtakoRow->作業３時間),
                0.00,
                ichinum($DtakoRow->作業２時間),
                ichinum($DtakoRow->作業４時間),
                // ichinum($DtakoRow->作業４時間),
                ichinum($DtakoRow->作業１時間 + $DtakoRow->作業２時間 + $DtakoRow->作業３時間),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
            ];
            // dump($DtakoRow);
            $keihi_chng = [5 => '08', 6 => '07'];

            foreach ($DtakoRow->dtako_keihi as $vv) {
                $data[] = [$ss . "2" . $ss, $ss . $keihi_chng[$vv->keihi_id] . $ss, 0, 0, $ss . "1" . $ss, $vv->value, $ss . "1" . $ss, 0, $ss . $ss, $ss . $vv->unko_date->i18nformat('yyyy/MM/dd') . $ss, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null];
                // dump($vv);
            }

            foreach ($DtakoRow->dtako_ferry_rows as $vv) {
                // dd($DtakoRow->dtako_ferry_rows);
                $data[] = [$ss . "2" . $ss, $ss . "07" . $ss, 0, 0, $ss . "1" . $ss, $vv->契約料金, $ss . "1" . $ss, 0, $ss . $vv->フェリー会社名 . ' ' . $vv->乗場名 . ' ' . $vv->降場名 . $ss, $ss . $vv->開始日時->i18nformat('yyyy/MM/dd') . $ss, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null];
                // dump($vv);
            }
            // dd($DtakoRow);
            foreach ($DtakoRow->dtako_etc as $vv) {
                // dd($vv);
                $data[] = [$ss . "2" . $ss, $ss . "06" . $ss, 0, 0, $ss . "1" . $ss, $vv->price, $ss . "1" . $ss, 0, $ss . $vv->start_name . $vv->start_etc_name . '～' . $vv->end_name . $vv->end_etc_name . $ss, $ss . $vv->end_datetime->i18nformat('yyyy/MM/dd') . $ss, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null];
                // dump($vv);
            }
            foreach ($DtakoRow->ryohi_rows as $vv) {
                // dd($vv);
                $shiharai = null;
                if ($vv->締日 == null) {
                    if (preg_match('/.*_フェリー.*/u', $vv->適用, $m)) {
                        $shiharai = $vv->支払日->i18nformat('Y/M/d');
                        // dd($DtakoRow->ryohi_rows);
                    } else {
                        $shiharai = $vv->支払日->subday(2)->i18nformat('Y/M/d');
                    }
                } else {
                    $shiharai = $vv->締日->i18nformat('Y/M/d');
                }
                $data[] = [$ss . "2" . $ss, $ss . "10" . $ss, $vv->残業, 0, $ss . "1" . $ss, is_null($vv->旅費) ? 0 : $vv->旅費, $ss . "1" . $ss, 0, $ss . $vv->適用 . $ss, $ss . $shiharai . $ss, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null];
                // dump($vv);
            }

            // dd($DtakoRow->車輌CC.sprintf('%04d',$DtakoRow->対象乗務員CD).$DtakoRow->出庫日時->i18nformat('yyyyMMddHHmm') . '.csv');

            $this->_makeichicsv($folder_path . '/' . $DtakoRow->読取日->i18nformat('yyyyMMdd'), $DtakoRow->車輌CC . sprintf('%04d', $DtakoRow->対象乗務員CD) . $DtakoRow->出庫日時->i18nformat('yyyyMMddHHmm') . '.csv', $data);
        }

        $file = null;

        $this->redirect('/');
        // dd($DtakoRows);
    }


    public function _makeichicsv($filepath = null, $filename = null, $data = null)
    {
        if ($filepath == null) {
            return false;
        }
        if ($filename == null) {
            return false;
        }
        if ($data == null) {
            return false;
        }

        $file = new \SplFileObject($filepath . '/' . $filename, 'a');
        $file->setCsvControl(',', '"', "\\");
        // $file->setFlags(
        //   \SplFileObject::READ_CSV |
        //   \SplFileObject::READ_AHEAD |
        //   \SplFileObject::SKIP_EMPTY |
        //   \SplFileObject::DROP_NEW_LINE
        // );

        // $lines = [];
        mb_convert_variables('SJIS-win', 'UTF8', $data);
        foreach ($data as $line) {
            // dd(implode(',',$line));
            $file->fwrite(implode(",", $line) . "\r\n");
            // $file->fwrite('\n');
            // $file->fputcsv($line,);
            // $file->fputcsv(mb_convert_encoding($line,'sjis-Win', 'utf-8',));    
            // $file->fseek(-1,1);
            // $file->fwrite("\r\n");
            // fseek($fps, -1, SEEK_CUR);
            // fwrite($fps, "\r\n");
        }

        $file = null;
        # code...
    }
}


/**
 * @property \App\Model\Table\DtakoRowsTable $DtakoRows
 * @property \App\Model\Table\DtakoEventsTable $DtakoEvents
 * @property \App\Model\Table\AppTable $model
 */
class Enter_data extends AppController
{

    public $entities;
    public $data;
    public $tmpDir;
    public $model;
    public $error;
    public $ids;
    protected $DtakoRows;
    /**
     * 
     * @param string|null $select 入力ファイル名
     * @param string|null $tmpdir 一時フォルダ
     */
    public function __construct(string $select = null, string $tmpdir = null)
    {
        $this->DtakoRows = $this->fetchTable('DtakoRows');
        Log::debug('' . __LINE__);
        $this->tmpDir = $tmpdir;
        Log::debug('' . __LINE__);
        $this->ids = [];
        Log::debug('' . __LINE__);
        $this->loadModel('DtakoEvents');
        Log::debug('' . __LINE__);
        $this->load($select);
        Log::debug('' . __LINE__);
        $this->make_data($select);
        Log::debug('' . __LINE__);
        $this->make_entities($select);
        Log::debug('' . __LINE__);
        // dd($this);
        if ($select == "KUDGIVT.csv") {
            // dump("t");
            $this->chg_two_man();
        }
    }


    public function chg_two_man()
    {

        if ($this->ids == null) {
            return false;
        }
        $tman = $this->array_two_man();
        // dd($tman);
        foreach ($tman as $vv) {
            $this->cdata_tman($vv);
        }
    }
    public function cdata_tman(string $id = null)
    {
        if ($id == null) {
            return false;
        }
        /** @var DtakoEvent[] $data  */
        $data = $this->DtakoEvents->find()->where(["運行NO in " => [$id . '1', $id . '2']])->order(['開始日時 asc', '終了日時 asc', '対象乗務員区分 asc'])->toArray();
        $data_R = [];
        foreach ($data as $vv) {
            if ($vv->イベントCD == null) { //イベントCDが0の運行開始については、開始時間が最初の作業とかぶるため、0に配置する。
                $data_R[substr($vv->srch_id, 0, -4)]['0'] = $vv;
            } else {

                if ($vv->対象乗務員区分 == 1) { //乗務員区分毎で分類する　srch_id -4 
                    $data_R[substr($vv->srch_id, 0, -4)]['1'] = $vv;
                } else {
                    $data_R[substr($vv->srch_id, 0, -4)]['2'] = $vv;
                }
            }
        }
        foreach ($data_R as $kk => $vv) {
            // foreach($vv as $kk1 => $vv1){
            // dump($vv);
            if (count($vv) > 1) {
                if ($vv['1']->イベント名 == '運転') {
                    // dump("test");
                    $data_R[$kk]['2']->非表示 = true;
                    if ($this->DtakoEvents->save($data_R[$kk]['2'])) {
                        // dump($data_R[$kk]['2']);
                    }
                } elseif ($vv['2']->イベント名 == '運転') {
                    $data_R[$kk]['1']->非表示 = true;
                    if ($this->DtakoEvents->save($data_R[$kk]['1'])) {
                        // dump($data_R[$kk]['1']);
                    }
                } else {
                    $data_R[$kk]['2']->非表示 = true;
                    $this->DtakoEvents->save($data_R[$kk]['2']);
                }
            }
            // }
        }
        // dd($data_R);
    }

    /** 
     * @return array 2マン運行の運行NO */
    public function array_two_man()
    {
        $array = [];
        foreach ($this->ids as $kk => $vv) {
            if (substr($vv, -1) == 2) {
                $array[] = substr($vv, 0, -1);
            }
        }
        return $array;
    }

    public function load(string $select = null)
    {
        switch ($select) {
            case null:
                return false;
                break;
            case 'KUDGURI.csv':
                $this->loadModel('DtakoRows');
                $this->model = $this->DtakoRows;
                // dd($this->model);
                break;
            case 'KUDGIVT.csv':
                $this->loadModel('DtakoEvents');
                $this->model = $this->DtakoEvents;
                // dd($this->model);
                break;
            case 'SokudoData.csv':
                $this->loadModel('Sokudodata');
                $this->model = $this->Sokudodata;
                break;
            case 'KUDGFRY.csv':
                $this->loadModel('DtakoFerryRows');
                $this->model = $this->DtakoFerryRows;
                break;
        }
        # code...
    }

    public function make_data(string $select = null)
    {
        if ($select == null) {
            return false;
        }
        $folder = new Folder($this->tmpDir);
        if ($folder->find($select)) {
            // foreach ($data as $ffile) { //DtakoEvent取込準備
            // dump($ffile);
            $fps = fopen($this->tmpDir . '/' . $select, 'r');
            $i = 0;
            $data_r = [];
            $d_flag = false;
            while (($fp = fgetcsv($fps, 0, ",")) !== FALSE) { //ファイル最終行まで読み込み
                $data_r[++$i] = mb_convert_encoding($fp, 'UTF-8', ['SJIS-win', 'SJIS', 'UTF-8']);  // エンコード
            }
            // if()
            $this->data = $data_r;
        }
        // $this->data=
    }

    public function is_error()
    {
        return $this->error;
    }


    public function make_entities(string $select = null)
    {
        if ($select == null) {
            return false;
        }
        $dd_title1 = [];

        if ($this->data == null) {
            // dd($select);
            return false;
        }
        Log::debug('' . __LINE__);
        foreach ($this->data as $kk => $vv) { //DtakoRows取り込み準備

            // $dddd = $this->DtakoEvents->newe($vv);

            Log::debug('' . __LINE__);
            if ($kk == 1) { // タイトル行取り込み準備
                Log::debug('' . __LINE__);
                $dd_title1 = [];
                foreach ($vv as $kk1 => $vv1) {
                    if (strpos($vv1, '(速度') > 0) {
                        // dd("stop");
                        $vv1 = str_replace(['(速度', '/h', ')'], '', $vv1);
                    }
                    $dd_title1[] = $vv1;
                }
            } else {
                Log::debug('' . __LINE__);
                $ddd = $this->model->newEmptyEntity();
                foreach ($vv as $kk1 => $vv1) {
                    if (strpos($dd_title1[$kk1], '速度') > 0) {
                        $vv1 = (float)$vv1;
                    }
                    if (in_array($dd_title1[$kk1], ['読取日', '運行日', '出社日時', '退社日時', '開始日時'])) {
                        $ddd[$dd_title1[$kk1]] = new DateTime($vv1);
                    } else {
                        $ddd[$dd_title1[$kk1]] = $vv1;
                    }
                }
                Log::debug('' . __LINE__);
                // if (array_key_exists('対象乗務員CD', $ddd) && ($ddd['対象乗務員CD'] == null || $ddd['対象乗務員CD'] == ""|| $ddd['対象乗務員CD'] == '')) { //対象乗務員区分がnullだとエラーになるので対策
                if ($ddd->has('対象乗務員CD') && ($ddd->対象乗務員CD == null || $ddd->対象乗務員CD == '')) { //対象乗務員区分がnullだとエラーになるので対策
                    // dd($ddd);
                    // unset($this->data[$kk]);
                    $ddd['対象乗務員CD'] = $ddd->乗務員CD1;
                    $ddd->対象乗務員CD = $ddd->乗務員CD1;
                }
                Log::debug('' . __LINE__);
                $ddd['車輌CC'] = sprintf('%04d', $ddd['車輌CD'] % 10000) . sprintf('%02d', floor($ddd['車輌CD'] / 10000));
                if ($select == 'KUDGURI.csv') {

                    Log::debug('' . __LINE__);
                    $ddd['id'] = $ddd['運行NO'] . $ddd['対象乗務員区分'];
                    // if ($ddd['対象乗務員CD'] == null || $ddd['対象乗務員CD'] == "") { //対象乗務員区分がnullだとエラーになるので対策
                    //     unset($this->data[$kk]);
                    // } else {

                    $this->ids[] = $ddd['id'];
                    if ($ddd['対象乗務員区分'] == 2) { //ツーマンの時、燃料が2倍になるので修正
                        $ddd['自社主燃料'] = 0;
                        $ddd['他社主燃料'] = 0;
                    }
                    $ddd['行先場所名'] = 'test';
                    $data_rr1[$ddd['id']][] = $ddd;
                    // }
                } elseif ($select == 'KUDGIVT.csv') {
                    Log::debug('' . __LINE__);
                    // dd("tst");
                    if ($ddd['イベントCD'] == null) {

                        $ddd['srch_id'] = $ddd['運行NO'] . $ddd['車輌CC'] . date_format($ddd['開始日時'], 'ymdHis') . '000' . $ddd['対象乗務員区分'];
                    } else {

                        $ddd['srch_id'] = $ddd['運行NO'] . $ddd['車輌CC'] .  date_format($ddd['開始日時'], 'ymdHis') . $ddd['イベントCD'] . $ddd['対象乗務員区分'];
                        // dd($ddd);
                    }
                    if ($ddd['開始場所CD'] == null) {
                        $ddd['開始場所CD'] = 0;
                    }
                    if ($ddd['終了場所CD'] == null) {
                        $ddd['終了場所CD'] = 0;
                    }
                    $ddd['運行NO'] = $ddd['運行NO'] . $ddd['対象乗務員区分'];
                    $this->ids[] = $ddd['運行NO'];
                    $data_rr1[$ddd['運行NO']][] = $ddd;
                } elseif ($select == "KUDGFRY.csv") {

                    $ddd['ferry_srch'] = $ddd['フェリー会社名'] . '_' . $ddd['乗場名'] . '_' . $ddd['降場名'];
                    $ddd['運行NO'] = $ddd['運行NO'] . '1';
                    $data_rr1[$ddd['運行NO']][] = $ddd;
                    // dd($data_rr1);
                } else {
                    $data_rr1[$ddd['運行NO']][] = $ddd;
                }
            }
        }
        // dd("test");

        Log::debug(__FILE__ . __LINE__);
        Log::debug($select . " making entity");
        // Log::debug($vv->getFilename());
        foreach ($data_rr1 as $kk => $vv) { //DtakoRows削除取込
            if ($select == 'KUDGURI.csv') {
                Log::debug(__FILE__ . __LINE__);
                // Log::debug(__FILE__ . __LINE__);
                // Log::debug($select);
                Log::debug($kk);
                // dd($data_rr1);
                if ($this->model->exists($kk)) {

                    Log::debug(__FILE__ . __LINE__ . "model exists " . (string)$kk);
                    // $this->model->deleteAll(['id in' => $kk]);
                    // dd($this->model);
                    // dd($kk);
                    Log::debug(var_export($vv, true));
                    $this->DtakoRows->deleteAll(['id' => $kk]);
                    // $this->model->deleteAll(['id'=>$kk]);
                    // $this->model->delete($this->model->get($kk));
                }
                // dd($vv);
                $this->load($select);
                // dd($this->model->saveMany($vv));
                Log::debug(__FILE__ . __LINE__);
            } else {
                $this->model->deleteAll(['運行NO in' => $kk]);
            }
            Log::debug(__FILE__ . __LINE__ . " " . (string)$kk . " " . $select);
            if ($this->model->saveMany($vv)) {
                Log::debug(__FILE__ . __LINE__);
            } else {
                Log::debug(__FILE__ . __LINE__);
                $this->error = true;
            }
        }
    }
}


/** 
 * @param dryohi_row $dtako_data 
 * @property \App\Model\Table\DtakoRowsTable $DtakoRows
 * @property \App\Model\Table\DtakoEventsTable $DtakoEvents
 *  */
class dryohi extends AppController
{

    /** 一番星からデータを検索し、更新
     * 入力 array($dtakoRows.id) 
     *
     * */

    /**
     * 車輌CC_社員CD_積み日 -> [当配,複日] =>卸日付=>得意先 
     */
    public $ichiban;
    public $ichiban_row;
    public $first_day;
    public $last_day;
    public $period;
    public $dtako_rows;
    public $dtako_rows_last;
    public $ids;
    public $dtako_data;

    // public function initialize(): void
    // {
    // parent::initialize();
    // $this->loadComponent('Flash');
    // }

    public function __construct(array $ids = null, string $shaban = null)
    {
        // $this->loadComponent('Flash');
        $this->loadModel('DtakoRows');
        $this->loadModel('DtakoEvents');
        if ($ids == null) {
            dump('die' . __LINE__);
            return false;
        } else {
            $this->ids = $ids;
        }
        $this->ichiban = [];

        // dd($shaban);
        // dump(__LINE__);
        // dd($this->ids);
        $this->set_periods($ids);
        // dump(__LINE__);
        // dd($this->period);
        $this->_reset_uriage($ids);
        foreach ($this->period as $dt) {
            $this->_ichiban_search($dt->format("Y-m-d"), $shaban);
        }

        $this->dtako_data = [];
        $this->dtako_events_reset($ids);
        // die;
        $this->set_dtako_rows($ids);

        // dd($this);
        // dd($this);   
        // $this->set_next_drows();
        // dd($this->dtako_data);
        $this->_dtako_row_search($ids);
        $this->_set_kusha($ids);
        $this->_set_ferry($ids);
        $this->_set_etc_data($ids);
        $this->etcAfterLastOroshi($ids);
        // dd("tst");
        // dump(__LINE__);
        // dd($this);
        // dd($this->dtako_data[0]);
        // dd(new dryohi_row($this->DtakoRows->get(['2206150633440000002813']), 'test'));

        // dd($this->ichiban);
    }

    public  function etcAfterLastOroshi($ids)
    {
        $this->check_ids($ids, __LINE__);
        foreach ($ids as $id) {

            $last_ev = $this->DtakoEvents->find()->where(['運行NO' => $id])
                ->orderAsc("開始日時")
                ->orderAsc("終了日時")
                ->orderAsc("イベントCD")
                ->all()
                ->last();
            if (!is_null($last_ev) && $last_ev->得意先 != null) {
                $input_data = $this->DtakoEvents->DtakoRows->EtcMeisaiAftOroshi->newEntity([
                    "dtako_row_id" => $id,
                    "price" => -1,
                ]);
                if ($this->DtakoEvents->DtakoRows->EtcMeisaiAftOroshi->save($input_data)) {
                } else {
                    dd($input_data);
                }
                // dump("積み置き分の計算について、etcAfterLastOroshiが対応していませんので、確認してください。");
                // dd(__FILE__ . "/" . __LINE__);
            } else { //積み置きでなければ、
                $last_oroshi = $this->DtakoEvents->find()->where(['運行NO' => $id, "イベント名" => "降し"])
                    ->orderAsc("開始日時")
                    ->orderAsc("終了日時")
                    ->orderAsc("イベントCD")
                    ->all()
                    ->last();
                $last_tsumi = $this->DtakoEvents->find()->where(['運行NO' => $id, "イベント名" => "積み"])
                    ->orderAsc("開始日時")
                    ->orderAsc("終了日時")
                    ->orderAsc("イベントCD")
                    ->all()
                    ->last();
                // dd($last_oroshi);
                if (isset($last_oroshi) && $last_oroshi != null && $last_tsumi != null && $last_oroshi->終了日時 > $last_tsumi->終了日時) { //降しが最後であれば、

                    $ddetc = $this->DtakoEvents->DtakoRows->EtcMeisai->find()->where(['EtcMeisai.dtako_row_id' => $id])
                        // ->contain(['DtakoUriageKeihi'])
                        ->where(function (QueryExpression $q) use ($last_oroshi) {
                            return $q
                                ->gte("date_to", $last_oroshi->開始日時)
                                ->gt("price", 0);
                        })
                        ->orderAsc('date_to')
                        ->orderDesc('date_fr')
                        // ->toArray()
                    ;
                    $ddetc_first = clone $ddetc;
                    $ddetc_first = $ddetc_first->all()->first();
                    $ddetc_last = clone $ddetc;
                    $ddetc_last = $ddetc_last->all()->last();
                    // dump($ddetc->last());
                    // dump($ddetc->first());

                    if ($ddetc->count()) {

                        $input_data = $this->DtakoEvents->DtakoRows->EtcMeisaiAftOroshi->newEntity([
                            "dtako_row_id" => $id,
                            "start_time" => is_null($ddetc_first->date_fr) ? $ddetc_first->date_to : $ddetc_first->date_fr,
                            "start_place" => is_null($ddetc_first->IC_fr) ? $ddetc_first->IC_to : $ddetc_first->IC_fr,
                            "end_time" => is_null($ddetc_last->date_to) ? $ddetc_last->date_fr : $ddetc_last->date_to,
                            "end_place" => is_null($ddetc_last->IC_to) ? $ddetc_last->IC_fr : $ddetc_last->IC_to,
                            "price" => $ddetc->all()->sumOf("price"),
                        ]);
                        // $ddetc->skip(0);
                        // dump($ddetc->sumOf("price"));
                        // dump($ddetc->toArray());
                        // $d1=0;   
                        // $ddetc->each(function($q)use( $d1)  {
                        //     $d1+=$q->price;
                        //     dd($q);
                        // });
                        // dd($d1);
                        // foreach ($ddetc->eac as $vv) {
                        //     $input_data->price+=$vv->price;
                        // }
                        // $dd
                        if ($this->DtakoEvents->DtakoRows->EtcMeisaiAftOroshi->save($input_data)) {
                            // dd($input_data);
                            // $this->Flash->success("ss");
                            // $this->Flash->warning("ss");
                        } else {

                            // $this->Flash->warning("ss");
                        }
                    } else {
                        // dd("st");
                        $input_data = $this->DtakoEvents->DtakoRows->EtcMeisaiAftOroshi->newEntity([
                            "dtako_row_id" => $id,
                            "price" => 0,
                        ]);
                        if ($this->DtakoEvents->DtakoRows->EtcMeisaiAftOroshi->save($input_data)) {
                        } else {
                            dd($input_data);
                        }
                    }
                    // dd($input_data);
                    // $EtcMeisaiAftOroshi=new \App\Controller\EtcMeisaiAftOroshiController();
                    // $EtcMeisaiAftOroshi->add
                } else {
                    // dd("st");
                    $input_data = $this->DtakoEvents->DtakoRows->EtcMeisaiAftOroshi->newEntity([
                        "dtako_row_id" => $id,
                        "price" => 0,
                    ]);
                    if ($this->DtakoEvents->DtakoRows->EtcMeisaiAftOroshi->save($input_data)) {
                    } else {
                        dd($input_data);
                    }
                }
                // dump($last_oroshi);
                // dump($last_tsumi);
                // dd($last_ev);
            }
        }
        # code...
    }

    public function _set_etc_data($ids)
    {
        $this->check_ids($ids, __LINE__);
        foreach ($ids as $id) {

            $ddetc = $this->DtakoEvents->DtakoRows->EtcMeisai->find()->where(['EtcMeisai.dtako_row_id' => $id])
                ->contain(['DtakoUriageKeihi'])
                ->orderAsc('date_to')
                ->orderAsc('date_fr');
            foreach ($ddetc as $dd1) {
                if ($dd1->dtako_uriage_keihi == null) { //経費登録が未登録の場合のみ実施

                    $tm_tokui_array = $this->DtakoEvents->find()->where(['運行NO' => $dd1->dtako_row_id])
                        ->where(['イベント名 in' => ['運行開始', '運転', '積み', '降し', '休憩', '休息']])
                        ->where(function (QueryExpression $st) use ($dd1) {
                            return $st->lt('開始日時', $dd1->date_to->i18nformat('yyyy-MM-dd HH:mm:ss'));
                        })->orderDesc('開始日時')->toArray();

                    if ($tm_tokui_array[0]->得意先 != null) { //etc終了日時より前のイベントに得意先が設定されていた場合、
                        $tm_tokui = $tm_tokui_array[0]->得意先;
                        // dd($tm_tokui);
                        $tm_srch_id = null;
                        $dd1->tsumi = [];
                        foreach ($tm_tokui_array as $is => $vvs1) {
                            if ($vvs1->得意先 == $tm_tokui) {
                                if ($vvs1->イベント名 == '運行開始') {    //運行開始まで得意先が同じ場合、
                                    $tm_id = $this->DtakoEvents->DtakoRows->find()->where(['乗務員CD1' => $vvs1->乗務員CD1, '車輌CC' => $vvs1->車輌CC])
                                        ->where(function (QueryExpression $qq1) use ($vvs1) {
                                            return $qq1->lte('帰庫日時', $vvs1->開始日時->i18nformat('yyyy-MM-dd HH:mm:ss'));
                                        })->orderDesc('出庫日時')->first();

                                    if (is_null($tm_id)) continue;
                                    $tm_id = $tm_id->id;
                                    // dump($tm_tokui_array);
                                    // dump($vvs1);
                                    // dump($tm_id);
                                    // dump( $this->DtakoEvents->DtakoRows->find()->where(['乗務員CD1' => $vvs1->乗務員CD1, '車輌CC' => $vvs1->車輌CC])
                                    // ->where(function (QueryExpression $qq1) use ($vvs1) {
                                    //     return $qq1->lte('帰庫日時', $vvs1->開始日時->i18nformat('yyyy-MM-dd HH:mm'));
                                    // })->orderDesc('出庫日時')->toArray());
                                    // dump(__LINE__);
                                    $tm_tokui_array_add = $this->DtakoEvents->find()->where(['運行NO' => $tm_id])
                                        ->where(['イベント名 in' => ['運行開始', '運転', '積み', '降し', '休憩', '休息', '運行終了']])
                                        ->where(function (QueryExpression $st) use ($dd1) {
                                            return $st->lt('開始日時', $dd1->date_to->i18nformat('yyyy-MM-dd HH:mm:ss'));
                                        })->orderDesc('開始日時')->toArray();

                                    // dd(__LINE__.__FILE__);
                                    if ($tm_tokui_array_add[0]->得意先 == $tm_tokui) {


                                        foreach ($tm_tokui_array_add as $is2 => $vvs2) {
                                            if ($vvs2->得意先 == $tm_tokui) {
                                            } else {
                                                // $dd1->tsumi[$tm_tokui_array_add[$is]->srch_id] = $tm_tokui_array_add[$is]->開始日時->i18nformat('MM/dd HH:mm') . ' ' . $tm_tokui_array_add[$is]->イベント名 . ' ' . $tm_tokui_array_add[$is]->開始市町村名;
                                                $ds1 = $this->DtakoRows->DtakoUriageKeihi->newEntity([
                                                    'srch_id' => $tm_tokui_array_add[$is2 - 1]->srch_id,
                                                    'datetime' => $dd1->date_to,
                                                    'price' => $dd1->price,
                                                    'dtako_row_id' => $dd1->dtako_row_id,
                                                    'keihi_c' => 2,
                                                    'manual' => false,
                                                ]);
                                                if ($this->DtakoRows->DtakoUriageKeihi->save($ds1)) {
                                                } else {
                                                    dd($ds1);
                                                }
                                                break 2;
                                            }
                                        }
                                        // dump($tm_tokui);
                                        // array_push($tm_tokui_array, $tm_tokui_array_add);
                                    }
                                    // dd($vvs1);
                                }
                            } else { //得意先が異なる→　一番最初のid
                                // dump('fin');
                                // dump($tm_tokui_array[$is - 1]);
                                // dd($tm_tokui_array[$is-1]);
                                // dd($dd1);
                                $ds1 = $this->DtakoRows->DtakoUriageKeihi->newEntity([
                                    'srch_id' => $tm_tokui_array[$is - 1]->srch_id,
                                    'datetime' => $dd1->date_to,
                                    'price' => $dd1->price,
                                    'dtako_row_id' => $dd1->dtako_row_id,
                                    'keihi_c' => 2,
                                    'manual' => false,
                                ]);
                                if ($this->DtakoRows->DtakoUriageKeihi->save($ds1)) {
                                } else {
                                    dd($ds1);
                                }
                                // $dd1->tsumi[$tm_tokui_array[$is - 1]->srch_id] = $tm_tokui_array[$is - 1]->開始日時->i18nformat('MM/dd HH:mm') . ' ' . $tm_tokui_array[$is - 1]->イベント名 . ' ' . $tm_tokui_array[$is - 1]->開始市町村名;
                                break;
                            }
                        }
                    } else { // 得意先が設定されていないので、乗務員都合をデフォでセット
                        $ds1 = $this->DtakoRows->DtakoUriageKeihi->newEntity([
                            'srch_id' => $tm_tokui_array[array_key_last($tm_tokui_array)]->srch_id,
                            'datetime' => $dd1->date_to,
                            'price' => $dd1->price,
                            'dtako_row_id' => $dd1->dtako_row_id,
                            'keihi_c' => 4,
                            'manual' => false,
                        ]);
                        if ($this->DtakoRows->DtakoUriageKeihi->save($ds1)) {
                            // dd($ds1);
                        } else {
                            dd($ds1);
                        }
                    }
                }
            }
        }
    }

    public function _reset_uriage($ids)
    {
        $this->check_ids($ids, __LINE__);
        foreach ($ids as $id) {

            $this->DtakoRows->DtakoUriageKeihiEtc->deleteall([ //事前に登録されている売り上げを削除
                'keihi_c in' => [0, 21, 22],
                'dtako_row_id' => $id,
            ]);
            $this->DtakoRows->DtakoUriageKeihiEtc->deleteall([ //事前に登録されている売り上げを削除
                'keihi_c in' => [2, 4],
                'dtako_row_id' => $id,
                'manual' => false,
            ]);
        }
        # code...
    }

    public function _set_ferry($ids = null)
    {
        $this->check_ids($ids, __LINE__);
        $dtako_ferry = TableRegistry::getTableLocator()->get('DtakoFerryRows');
        foreach ($ids as $id) {

            $d2 = $dtako_ferry->find()->where(['運行NO' => $id])
                ->orderAsc('開始日時')->toArray();
            // dd($d2);
            array_map(function ($q) {
                // dd('test');
                $f_ev = $this->DtakoEvents->find()
                    ->where(['運行NO' => $q->運行NO, 'イベント名 in' => ['積み', '降し', '運転', '休憩', '休息', '待機']])
                    ->where(function (QueryExpression $q21) use ($q) {

                        return $q21->lte('開始日時', $q->開始日時->i18nformat('yyyy-MM-dd HH:mm'));
                    })
                    ->order(['開始日時 desc'])->toArray();
                // foreach($f_ev as $vv){
                //     dump($vv->開始日時->i18nformat('yyyy/MM/dd HH:mm'));
                // dd($q);
                // }
                // dd($f_ev[0]);
                if ($f_ev[0]->得意先 != null) {
                    $tokui_tmp = $f_ev[0]->得意先;
                    $tmp_srch_id = null;
                    foreach ($f_ev as $ff) {
                        // dd($ff);
                        if ($ff->得意先 == $tokui_tmp) {
                            $tmp_srch_id = $ff->srch_id;
                        } else {
                            // $this->DtakoEvents->DtakoRows->DtakoUriageKeihi->deleteall([
                            //     'dtako_row_id' => $q->運行NO,
                            //     'keihi_c' => 21,
                            // ]);
                            $d1 = $this->DtakoEvents->DtakoRows->DtakoUriageKeihi->newEntity([
                                'srch_id' => $tmp_srch_id,
                                'price' => $q->契約料金,
                                'datetime' => $q->開始日時,
                                'keihi_c' => 21,
                                'dtako_row_id' => $q->運行NO,
                                'dtako_row_id_r' => substr($q->運行NO, 0, 22),
                            ]);
                            if ($this->DtakoEvents->DtakoRows->DtakoUriageKeihi->save($d1)) {

                                // dd($d1);
                                // $this->Flash->success('save success ferry keihi');
                            } else {
                                // dd($d1);
                                // $this->Flash->error('save fail ferry keihi');
                            }
                            break;
                        }
                    }
                } else {
                    $f_ev = $this->DtakoEvents->find()
                        ->where(['運行NO' => $q->運行NO, 'イベント名' => '運行開始'])->orderasc('開始日時')->first();

                    $d1 = $this->DtakoEvents->DtakoRows->DtakoUriageKeihi->newEntity([
                        'srch_id' => $f_ev->srch_id,
                        'price' => $q->契約料金,
                        'datetime' => $q->開始日時,
                        'keihi_c' => 22, //空車フェリー
                        'dtako_row_id' => $q->運行NO,
                        'dtako_row_id_r' => substr($q->運行NO, 0, 22),
                    ]);
                    if ($this->DtakoEvents->DtakoRows->DtakoUriageKeihi->save($d1)) {
                    }
                }
                // return $q;
            }, $d2);
            // ;
            // dd($d2);\\
            // ->map(function ($q) {
            //     dd($q);
            //     return $q;
            //     }
            // )
            // ;
            // dd($d2);
        }

        # code...
    }


    public function _set_kusha(array $ids = null)
    {

        // $this->loadComponent('Flash');
        $this->check_ids($ids, __LINE__);
        // dd($ids);
        foreach ($ids as $id) {
            $array = $this->DtakoEvents->find()->where(['DtakoEvents.運行NO in' => $id])
                ->where(['イベント名' => '運転'])
                ->order(['DtakoEvents.開始日時 asc'])
                ->toarray();
            $first = $this->DtakoEvents->find()->where(['DtakoEvents.運行NO in' => $id])
                ->where(['イベント名' => '運行開始'])
                ->order(['DtakoEvents.開始日時 asc'])
                ->first();
            $tmp = 0;
            foreach ($array as $vv) {
                if ($vv->得意先 == "") {
                    $tmp += $vv->区間距離;
                }
            }
            // if($first->srch_id==null){
            //     dump(substr($id,-1));
            //     dump(__FILE__.__LINE__);
            //     dd($first);
            // }
            if (substr($id, -1) != "2") {

                $dt = TableRegistry::getTableLocator()->get('DtakoUriageKeihi');
                // dd($tmp);
                if ($first != null && $first->srch_id != null) {

                    $d1 = $dt->newEntity([
                        'srch_id' => $first->srch_id,
                        'price' => 0,
                        'km' => $tmp,
                        'datetime' => $first->開始日時->i18nformat('yyyy-MM-dd HH:mm:ss'),
                        'dtako_row_id' => $id,
                        'dtako_row_id_r' => substr($id, 0, 22),
                        'keihi_c' => 0,
                    ]);
                    if ($dt->save($d1)) {
                        //     $this->Flash->success('test');
                    } else {
                        // dd($d1);
                        // $this->Flash->error('ss');
                    };
                }
            }
        }
    }

    /**dtakoEventをリセット
     * 運行開始に得意先名が設定されていた場合、
     * 次の降しまでリセットされない。
     */
    public function dtako_events_reset(array $ids = null)
    {

        $this->check_ids($ids, __LINE__);
        $dtakoE_reset = $this->DtakoEvents->find()->where(['DtakoEvents.運行NO in' => $ids])
            ->contain('DtakoEventsDetails')->toarray();
        // dd($dtakoE_reset->toarray());
        $ck_oroshi_fst = null;
        foreach ($dtakoE_reset as $vv) {
            if ($ck_oroshi_fst != null) {
                continue;
            }
            if ($vv->イベント名 == '積み') {
                $ck_oroshi_fst = 2;
            }
            if ($vv->イベント名 == '降し') {
                $ck_oroshi_fst = 1;
            }
        }
        // dd($ck_oroshi_fst);
        foreach ($dtakoE_reset as $vv) {
            // if ($vv['イベント名'] == '運行開始' and $vv['得意先'] != null) {
            //     $ck_oroshi_fst = 1;
            // }

            // if ($vv['イベント名'] == '積み') {　　//０１１８除外リセットされない問題解消のため
            //     $ck_oroshi_fst = 1;
            // }
            if ($ck_oroshi_fst == 1 and $vv->イベント名 != '降し') {
                continue;
            } elseif ($ck_oroshi_fst == 1 and $vv->イベント名 == '降し') {
                continue;
            } elseif ($ck_oroshi_fst == 1 and $vv->イベント名 == '積み') {
                $ck_oroshi_fst = 2;
            }
            // dump(__LINE__);
            // dd($dtakoE_reset);
            // dd($ck_oroshi_fst);
            // if ($ck_oroshi_fst == null) {

            $vv->得意先 = null;
            $vv->unten_o9_time = null;
            $vv->kosoku_o15_time = null;
            // dump($vv);
            if ($vv->dtako_events_detail != null) {
                $vv->得意先 = $vv->dtako_events_detail->備考;
            }
            // }

            //降しで得意先がNullの判定を先に行うと、降し部分の削除が先になるため、削除後に判定
            // if ($vv['イベント名'] == '降し' and $vv['得意先'] != null) {

            //     $ck_oroshi_fst = null;
            // }
        }
        $this->DtakoEvents->saveMany($dtakoE_reset);
        // foreach($dtakoE_reset as $vv){
        //     dump($vv->得意先);
        // }
        // dd($dtakoE_reset);
    }
    /**
     * idがnullの場合、ddでfile名、lineを返す
     */
    public function check_ids(array $ids = null, int $line = null)
    {
        if ($ids == null) {
            dd(__FILE__ . ' ' . $line);
            return false;
        }
    }

    /**設定したすべてDtakoRowsに積み下ろしを設定 */
    public function set_next_drows()
    {
        /** 設定したすべてのDtakoRowsに積み下ろしを設定 */
        foreach ($this->dtako_data as $key => $drow) {
            //それぞれが積で終了しているのか
            if ($drow->is_tsumi_end()) {
                //次のが卸で終了したら、
                // dd("test");
                $lastd = $this->ck_ichi_lastday($drow);
                // dd($lastd);
                $drow->set_next_drow($lastd);
            }
        }
    }

    public function ck_ichi_lastday(dryohi_row $drow = null)
    {
        //一番星で終了日を特定
        if ($drow->last_tsumi_row == null) {
            return null;
        }
        $lastday = $drow->last_tsumi_row->開始日時->i18nformat('yMMdd');
        $driver = (string)$drow->last_tsumi_row->乗務員CD1;
        $car = (string)$drow->last_tsumi_row->車輌CC;
        $lastD = null;
        foreach ($this->ichiban_row as $key => $vv) {
            if ($lastday == $vv['積込年月日'] and $vv['運転手C'] == $driver and $vv['車輌CC'] == $car) {
                if ($lastD < (int)$vv['納入年月日']) {
                    $lastD = $vv['納入年月日'];
                }
            }
        }
        return (string)$lastD;
    }


    /**
     * 運行NOのうち、最小の出庫日時と最大の帰庫日時を設定
     */
    public function set_periods(array $ids = null)
    {


        if ($ids == null) {
            dd(__FILE__ . ' ' . __LINE__);
            return false;
        }
        // dump(__LINE__);
        // dd($ids);
        $this->first_day = $this->DtakoRows->find()
            ->where(['DtakoRows.id in ' => $ids])
            ->all()
            ->min('出庫日時');
        $this->last_day = $this->DtakoRows->find()
            ->where(['DtakoRows.id in ' => $ids])
            ->all()
            ->max('帰庫日時');


        $interval = \DateInterval::createFromDateString('1 day');
        $this->period = new \DatePeriod($this->first_day->出庫日時->modify('- 1 day'), $interval, $this->last_day->帰庫日時->modify('+2day'));
    }

    /**
     * @param string|null $shaban
     */
    public function _ichiban_search($date = null, string $shaban = null) //一番星から出力用データを検索
    {

        // dump($date);
        // dump($shaban);
        $conn = ConnectionManager::get('ichi');
        if ($shaban == null) {

            $result = $conn->newQuery()
                ->select(["format(積込年月日,'yyyyMMdd') as 積込年月日", "format(運行年月日,'yyyyMMdd') as 運行年月日", '運転手C', "format(納入年月日,'yyyyMMdd') as 納入年月日", '車輌C+車輌H as 車輌CC', '得意先C+得意先H as 得意先CC', "format(積込年月日,'yyyyMMdd')+'_'+車輌C+車輌H+'_'+運転手C as 検索"])
                ->from('[運転日報明細]')
                ->where(['配車K' => 0, '日報K' => 1, '請求K is not ' => 1, '車輌C not in' => ['0001', '0000'], '得意先C not in ' => ['000002']])
                ->where(["Format(積込年月日,'yyyy-MM-dd')" => $date])
                ->order(['車輌C asc', '運転手C asc', '運行年月日 asc', '積込年月日 asc', '納入年月日 asc'])
                ->execute()->fetchAll('assoc');;
        } else {
            $result = $conn->newQuery()
                ->select(["format(積込年月日,'yyyyMMdd') as 積込年月日", "format(運行年月日,'yyyyMMdd') as 運行年月日", '運転手C', "format(納入年月日,'yyyyMMdd') as 納入年月日", '車輌C+車輌H as 車輌CC', '得意先C+得意先H as 得意先CC', "format(積込年月日,'yyyyMMdd')+'_'+車輌C+車輌H+'_'+運転手C as 検索"])
                ->from('[運転日報明細]')
                ->where(['配車K' => 0, '日報K' => 1, '請求K is not ' => 1, '車輌C not in' => ['0001', '0000'], '得意先C not in ' => ['000002']])
                ->where(["Format(積込年月日,'yyyy-MM-dd')" => $date, '車輌C+車輌H' => $shaban])
                ->order(['車輌C asc', '運転手C asc', '運行年月日 asc', '積込年月日 asc', '納入年月日 asc'])
                ->execute()->fetchAll('assoc');;
        }

        // dump($result);
        foreach ($result as $vv) {
            // dump($vv);
            $this->ichiban_row[] = $vv;
        }

        $result3 = [];
        foreach ($result as $kk => $vv) {
            if (intval($vv['積込年月日']) < intval($vv['運行年月日'])) {
                $this->ichiban[$vv['検索']]['前積'][$vv['納入年月日']][$vv['得意先CC']] = 1;
            } elseif (($vv['納入年月日'] == $vv['積込年月日'])) {
                $this->ichiban[$vv['検索']]['当配'][$vv['納入年月日']][$vv['得意先CC']] = 1;
            } else {
                $this->ichiban[$vv['検索']]['複日'][$vv['納入年月日']][$vv['得意先CC']] = 1;
            }
        }
        $this->ichiban = $this->ichiban + $result3;
        // dump($date);
        // dump($this->ichiban);
        // dump(__LINE__);
        // dd($this->ichiban);
    }


    /**
     * $this->dtako_rows、$this->dtako_dataを設定
     * @param array $ids
     */
    public function set_dtako_rows(array $ids = null)
    {
        # code...
        $this->check_ids($ids, __LINE__);
        $distinct = $this->DtakoRows->find()
            ->select(['検索' => "concat(right(concat('000000',cast(車輌CC as CHAR)),6),'_',right(concat('0000',cast(乗務員CD1 as CHAR)),4))"])
            ->where(['DtakoRows.id in ' => $ids])
            // ->where(['総走行距離 is not' => 0])// 2マン運行の集計時、０距離が発生するため、除去　230209
            ->where(['乗務員CD1 is not' => 0])
            ->distinct(['車輌CC', '乗務員CD1']);
        $start = $this->first_day->出庫日時;
        $this->dtako_rows = $this->DtakoRows->find()
            ->where(["concat(right(concat('000000',cast(車輌CC as CHAR)),6),'_',right(concat('0000',cast(乗務員CD1 as CHAR)),4)) in" => $distinct])
            ->where(["対象乗務員区分" => 1])
            ->where(function (QueryExpression $q) use ($start) {
                return $q->gte('出庫日時', $start);
            })
            ->contain(
                ['DtakoEvents' => function (Query $q) {
                    return $q->where([
                        ' DtakoEvents.イベント名 in ' => ['運行開始', '運転', '休憩', '休息', '積み', '降し', '運行終了'],
                        ['DtakoEvents.非表示 is null ']
                    ])->orderAsc('開始日時')->orderAsc('終了日時')->orderAsc('イベントCD');
                }]
            )
            ->contain('DtakoEvents.DtakoEventsDetails')
            // ->where(['総走行距離 is not' => 0])// 2マン運行の集計時、０距離が発生するため、除去　230209
            ->order(['DtakoRows.車輌CC asc', 'DtakoRows.乗務員CD1 asc',  'DtakoRows.出庫日時 asc'])->toArray();

        // dd("test    ");
        $count = count($this->dtako_rows);
        $array = [];
        foreach ($this->dtako_rows as $key => $vv) {
            // dd($count);
            // dd($key);
            if (in_array($vv->id, $ids)) {

                $dry = new dryohi_row($vv);
                // dump($this);
                // dump(__FILE__);
                // dd($dry);
                if ($count - 1 > $key) {
                    if ($vv['車輌CC'] == $this->dtako_rows[$key + 1]['車輌CC'] and $vv['乗務員CD1'] == $this->dtako_rows[$key + 1]['乗務員CD1'] and $dry->is_tsumi_end()) {
                        // if ($vv['車輌CC'] == $this->dtako_rows[$key + 1]['車輌CC'] and $vv['対象乗務員CD'] == $this->dtako_rows[$key + 1]['対象乗務員CD'] and $dry->is_tsumi_end()) {
                        // dd($this->dtako_rows);
                        $dry->is_next_oroshi_fst($this->dtako_rows[$key + 1]);

                        $dry->set_next_drow($this->dtako_rows[$key + 1]);

                        //この部分にひとつ前の接続を検索する処理を記載
                    }
                }
                // $array[$vv['車輌CC'].'_'.$vv['乗務員CD1']][]=$vv;
                $this->dtako_data[] = $dry;
            }
        }

        // dd(count($this->dtako_data));
    }


    public function _dtako_row_search($ids = null, $shaban = null)
    {
        // dump(__FILE__.__LINE__);
        // dd($this);
        // dd($this->dtako_data);
        // dd(__FILE__.__LINE__);
        foreach ($this->dtako_data as $kk => $dtako_data) {
            /**
             * 積みデータのうち、一番星にあるデータを出力
             * @var array $tsumi_ichi_data*/
            $tsumi_ichi_data = [];
            if ($tsumi_ichi_data = $this->array_in_dtako_tsumi_data_and_ichiban($dtako_data)) {
                // dump($tsumi_ichi_data);
                // dump(__LINE__);

                log::debug(__FILE__ . __LINE__ . " searching dtako_row");
                // Log::debug(var_export($dtako_data, true));
                $this->check_set_ichi_data($tsumi_ichi_data, $dtako_data);
            } else {

                // dump($this);
                // dump(__LINE__);
            }
        }
    }


    public function array_in_dtako_tsumi_data_and_ichiban(dryohi_row $var = null)
    {
        if ($var == null) {
            return false;
        }
        $array = [];
        // dd($var);
        foreach ($var->積 as $key => $value) {
            if (array_key_exists($key, $this->ichiban)) {
                $array[$key] = $this->ichiban[$key];
            }
            // dump($var);
        }
        return $array;
        # code...
    }

    /**
     *  @param array $ichi 一番星とデジタコに積データのあるデータ 
     *  @param array $oroshi デジタコ上の卸データ*/
    public function check_set_ichi_data(array $ichi = null, dryohi_row $d_ryohi_r = null)
    {
        if ($ichi == null) {
            return false;
        }
        if ($d_ryohi_r == null) {
            return false;
        }

        $array = [];
        // dd($ichi);
        // dd("t");
        // dump(__FILE__.__LINE__);
        // dd($d_ryohi_r);
        // dd($ichi);
        foreach ($ichi as $tsumi_date => $kubun_b) {

            foreach ($kubun_b as $kubun => $oroshi_date_b) {
                // dd($this->ichiban);
                foreach ($oroshi_date_b as $oroshi_date => $tokui_b) {
                    if (count($tokui_b) >= 2) {
                        $tokui = "複数";
                    } else {
                        $tokui = array_key_first($tokui_b);
                    }
                    // dump($oroshi_date);
                    // dump($d_ryohi_r);
                    if ($d_ryohi_r->is_in_oroshi((string)$oroshi_date)) {
                        // if ($d_ryohi_r->積 != null and $d_ryohi_r->積 != [] and isset($d_ryohi_r->積)) { //積がない部分について、エラーが出るので対処
                        // dump($tsumi_date);
                        // dump($kubun);   
                        // dump($d_ryohi_r);   
                        // if(!$d_ryohi_r->first_tsumi_row==null){
                        try {

                            $this->set_tokui($tsumi_date, $kubun, (string)$oroshi_date, (string)$tokui, $d_ryohi_r);
                        } catch (Exception $e) {
                            dd($e);
                        }
                        $d_ryohi_r->calc_fin();
                        // }
                        // }
                        // dd($oroshi_date);
                        // $array[] = $this->check_ichi_data($ichi, (string)$oroshi_date, $kubun);
                    }
                }
            }
        }
        // dd("test;");
    }

    /**
     * 卸日に当配、複日が両方存在
     */
    public function is_other_tsumi_kubun_exists(string $tsumi_date = null)
    {
        if ($tsumi_date == null) {
            return false;
        }

        return count($this->ichiban[$tsumi_date]) > 1;
    }

    /**
     * 一番星の入力から、デジタコのデータを結合
     */
    public function set_tokui(string $unko_date = null, string $kubun = null, string $oroshi_date = null, string $tokuisaki = null, dryohi_row $dryohi = null)
    {

        /**得意先を設定 */
        /**区分ごと処理内容を変更 */
        /**区分が当配の場合 当日の一番の積を取得 、当日の最後の積を取得 */
        // dd($dryohi->data->出庫日時->i18nformat('yMMdd'));
        if ($unko_date == null or $dryohi->積 == null or $dryohi->積 == []) {
            dd(__LINE__);
            return false;
        }
        // dump(__LINE__);
        // dd($dryohi);
        // dump($dryohi);\
        if (!array_key_exists($unko_date, $dryohi->積)) {
            dd(__LINE__);
            return false;
        };
        if ($dryohi->積[$unko_date] == []) {
            dd(__LINE__);
            return false;
        };
        $unko_d = substr($unko_date, 0, 8);
        // dump($dryohi);

        /** @var bool $flag 卸日に同日、複日が混在する場合 */
        $flag = $this->is_other_tsumi_kubun_exists($unko_date);
        // dump($dryohi);
        // dump($flag);
        // dump($kubun);
        // dump( intval($dryohi->data->出庫日時->i18nformat('yMMdd')));

        // dd($kubun);
        if ($kubun == "前積" and $flag == false) {
            // dd($dryohi);

        }
        if ($kubun == "当配" and $flag == false) {
            // dd($dryohi);
            $first = array_key_first($dryohi->積[(string)$unko_date]);
            $last = array_key_last($dryohi->卸[(string)$oroshi_date]);
            $start_time = $dryohi->積[(string)$unko_date][$first]->開始日時;
            $last_time = $dryohi->卸[(string)$oroshi_date][$last]->開始日時;
            // dump('当配');
            // dump($start_time->i18nformat('yyyy-MM-dd HH:mm:ss'));
            // dump($last_time->i18nformat('yyyy-MM-dd HH:mm:ss'));
        } else if ($kubun == "複日" and $flag == false) {
            $first = array_key_first($dryohi->積[(string)$unko_date]);
            // $last = array_key_last($dryohi->卸[(string)$oroshi_date]);
            // $last = array_key_last($dryohi->卸[(string)$oroshi_date]); //0117 変更 last->first
            $last = array_key_last($dryohi->卸[(string)$oroshi_date]); //0117 変更 last->first
            $start_time = $dryohi->積[(string)$unko_date][$first]->開始日時;
            $last_time = $dryohi->卸[(string)$oroshi_date][$last]->開始日時;
            // $last_time = $dryohi->卸[(string)$oroshi_date][$last]->開始日時; //0117 変更　$last->$first
            // dump('複日');
            // dump($start_time->i18nformat('yyyy-MM-dd HH:mm:ss'));
            // dump($last_time->i18nformat('yyyy-MM-dd HH:mm:ss'));
            // } else if ($kubun == "前積" and $flag == false and $unko_d = $dryohi->data->出庫日時->i18nformat('yMMdd')) {
        } else if ($kubun == "前積" and $flag == false and intval($unko_d) < intval($dryohi->data->出庫日時->i18nformat('yMMdd'))) {
            /**前積みで、積日が出庫日時以前の場合、積日の運行開始から計算　*/

            // dd($dryohi);
            $last = array_key_last($dryohi->卸[(string)$oroshi_date]);
            // $first = array_key_first($dryohi->積[(string)$unko_date]);
            $start_time = $dryohi->data->出庫日時;
            $last_time = $dryohi->卸[(string)$oroshi_date][$last]->開始日時;
        } else if ($kubun == "前積" and $flag == false and intval($unko_d) <= intval($dryohi->data->帰庫日時->i18nformat('yMMdd'))) {
            // dd($dryohi);

            $first = array_key_first($dryohi->積[(string)$unko_date]);
            $last = array_key_last($dryohi->卸[(string)$oroshi_date]);
            $start_time = $dryohi->積[(string)$unko_date][$first]->開始日時;
            $last_time = $dryohi->卸[(string)$oroshi_date][$last]->開始日時;
            // dd($dryohi);
        } else if ($kubun == "前積" and $flag == false and intval($unko_d) > intval($dryohi->data->帰庫日時->i18nformat('yMMdd'))) {
            /**前積みで、積日がの場合、積日の最初の積から計算　*/
        } else if ($kubun == "前積" and $flag == false and intval($unko_d) >= intval($dryohi->data->出庫日時->i18nformat('yMMdd'))) {

            /**前積みで、積日が出庫日時以後の場合、積日の最初の積から計算　*/
            $first = array_key_first($dryohi->積[(string)$unko_date]);
            $last = array_key_last($dryohi->卸[(string)$oroshi_date]);
            $start_time = $dryohi->積[(string)$unko_date][$first]->開始日時;
            $last_time = $dryohi->卸[(string)$oroshi_date][$last]->開始日時;
        } else if ($kubun == "前積" and $flag == true and intval($unko_d) >= intval($dryohi->data->出庫日時->i18nformat('yMMdd'))) {
            // dd($dryohi);
            /**前積みで、積日が出庫日時以後の場合、積日の最初の積から計算　*/
            $first = array_key_last($dryohi->積[(string)$unko_date]);
            $last = array_key_last($dryohi->卸[(string)$oroshi_date]);
            $start_time = $dryohi->積[(string)$unko_date][$first]->開始日時;
            $last_time = $dryohi->卸[(string)$oroshi_date][$last]->開始日時;
        } else if ($kubun == "当配" and $flag == true) {
            // dump($last_time);
            // dump($unko_date);
            // dd($dryohi);
            $first = array_key_first($dryohi->積[(string)$unko_date]);
            $last = array_key_last($dryohi->卸[(string)$oroshi_date]);
            $start_time = $dryohi->積[(string)$unko_date][$first]->開始日時;
            $last_time = $dryohi->卸[(string)$oroshi_date][$last]->開始日時;
        } else if ($kubun == "複日" and $flag == true) {
            $first = array_key_last($dryohi->積[(string)$unko_date]);
            // $last = array_key_first($dryohi->卸[(string)$oroshi_date]);
            $last = array_key_last($dryohi->卸[(string)$oroshi_date]);
            // dump($dryohi->積[(string)$unko_date]);
            // dump($dryohi->積[(string)$unko_date][$first]);
            $start_time = $dryohi->積[(string)$unko_date][$first]->開始日時;
            $last_time = $dryohi->卸[(string)$oroshi_date][$last]->開始日時;
            // dump('複日 true');
            // dump($start_time->i18nformat('yyyy-MM-dd HH:mm:ss'));
            // dump($last_time->i18nformat('yyyy-MM-dd HH:mm:ss'));
        }

        // if ($kubun == '当配' or $kubun == "複日" or ) {
        if (isset($start_time)) {
            $kyori = 0;
            foreach ($dryohi->data->dtako_events as $key => $val) {
                // dd($val);
                // if(isset($last_time)){

                if ($val->開始日時 >= $start_time and $last_time >= $val->開始日時) {
                    // dump( $dryohi->data->dtako_events[$key]);
                    // dump($dryohi->data->dtako_events[$key]->開始日時->i18nformat('hh:mm'));
                    // dd($dryohi->data->dtako_events[$key]);
                    $kyori += $dryohi->data->dtako_events[$key]->区間距離;
                    $dryohi->data->dtako_events[$key]->得意先 = $tokuisaki;
                }
                // }
            }

            // dd($kyori);
            $conn = ConnectionManager::get('ichi');
            if ($dryohi->data != null) {

                $result = $conn->newQuery()
                    // ->select([
                    //     "format(積込年月日,'yyyyMMdd') as 積込年月日", "format(運行年月日,'yyyyMMdd') as 運行年月日",
                    //     '運転手C', "format(納入年月日,'yyyyMMdd') as 納入年月日", '車輌C+車輌H as 車輌CC',
                    //     '得意先C+得意先H as 得意先CC', "format(積込年月日,'yyyyMMdd')+'_'+車輌C+車輌H+'_'+運転手C as 検索"
                    // ])
                    ->select(['sum' => 'sum(金額+割増-値引+実費)'])
                    ->from('[運転日報明細]')
                    ->where(['配車K' => 0, '日報K' => 1, '請求K is not ' => 1, '車輌C not in' => ['0001', '0000']])
                    ->where(["Format(積込年月日,'yyyy-MM-dd')" => $start_time->i18nformat('yyyy-MM-dd')])
                    ->where(["Format(納入年月日,'yyyy-MM-dd')" => $last_time->i18nformat('yyyy-MM-dd')])
                    ->where(["運転手C" => $dryohi->data->乗務員CD1])
                    ->where(["車輌C+車輌H" => $dryohi->data->車輌CC])
                    ->group(['車輌C', '車輌H', '運転手C'])
                    // ->order(['車輌C asc', '運転手C asc', '運行年月日 asc', '積込年月日 asc', '納入年月日 asc'])
                    ->execute()->fetchAll('assoc');
                // dump($dryohi->積[(string)$unko_date][$first]->srch_id);
                // dump($kubun);
                // dump($oroshi_date);
                // dd($dryohi);

                if ($result != null) {

                    $this->DtakoRows->DtakoUriageKeihiEtc->addData([
                        'price' =>  $result[0]['sum'],
                        'keihi_c' => 0,
                        'km' => $kyori,
                        'datetime' => $start_time,
                        'srch_id' => $dryohi->積[(string)$unko_date][$first]->srch_id,
                        'dtako_row_id' => $dryohi->data->id,
                        'start_srch_id' => $dryohi->積[(string)$unko_date][$first]->srch_id,
                        'start_srch_time' => $dryohi->積[(string)$unko_date][$first]->開始日時,
                        'start_srch_place' => $dryohi->積[(string)$unko_date][$first]->開始市町村名,
                        'start_srch_tokui' => $dryohi->積[(string)$unko_date][$first]->得意先,
                        'end_srch_id' => $dryohi->卸[(string)$oroshi_date][$last]->srch_id,
                        'end_srch_time' => $dryohi->卸[(string)$oroshi_date][$last]->開始日時,
                        'end_srch_place' => $dryohi->卸[(string)$oroshi_date][$last]->開始市町村名,
                    ]);
                    //経費　
                    // $result = $conn->newQuery()
                    // ->select(['tanka' => '単価'])
                    // ->from('[経費明細]')
                    // ->where(["Format(運行年月日,'yyyy-MM-dd')" => $dryohi->data->運行日->i18nformat('yyyy-MM-dd')])
                    // ->where(["運転手C" => $dryohi->data->乗務員CD1])
                    // ->where(["車輌C+車輌H" => $dryohi->data->車輌CC])
                    // // ->where(["経費C" => $dryohi->data->車輌CC])
                    // ->where(["経費C" => '0621'])
                    // ->where(["未払先C" => '000001'])
                    // ->where(["未払先H" => '000'])
                    // // ->group(['車輌C', '車輌H', '運転手C'])
                    // // ->order(['車輌C asc', '運転手C asc', '運行年月日 asc', '積込年月日 asc', '納入年月日 asc'])
                    // ->execute()->fetchAll('assoc');
                    // dd($dryohi->data[0]);
                    // if($result!=null){

                    //     $this->DtakoRows->DtakoUriageKeihiEtc->addData([
                    //         'price' =>  $result[0]['tanka'],
                    //         'keihi_c' =>9,
                    //         'datetime' =>$dryohi->data->出庫日時->i18nformat('yyyy-MM-dd HH:mm:ss'),
                    //         'srch_id' =>$dryohi->data->dtako_events[0]->srch_id,
                    //         'dtako_row_id' =>$dryohi->data->id,
                    //     ]);
                    // }
                    // dd($result);
                }
            }
        }
        // }
        // dump($dryohi->data->dtako_events);
        $this->DtakoEvents->savemany($dryohi->data->dtako_events);
    }
}

/**
 * @param \App\Model\Entity\DtakoRow $v
 * @property \App\Model\Table\DtakoEventsTable $DtakoEvents
 * @property \app\Model\Entity\DtakoEvent $data
 * @property \App\Model\Table\DtakoRowsTable $DtakoRows
 * @property boolean $maedumi_flg 前積みしたかどうか
 * @property \App\Model\Table\DtakoEventsTable $maedumi_data 前積data
 * @method is_oroshi_fst
 */
class dryohi_row extends AppController
{
    public $data;
    public $string;
    public $積;
    public $卸;
    public $next_row;
    public $is_tsumi_end;
    public $oroshi_fst;
    public $first_oroshi_row;
    public $last_oroshi_row;
    public $next_first_oroshi_row;
    public $first_tsumi_row;
    public $last_tsumi_row;
    public $last_row;
    public $maedumi_flg;
    public $maedumi_data;

    public function __construct($v)
    {
        $this->loadModel('DtakoEvents');
        $this->loadModel('DtakoRows');
        $this->data = $v;
        $this->set_tsumi_oroshi();
        $this->set_last_row();
        $this->ck_set_maedumi();
        // dump(__LINE__);
        // dd($this);
    }

    public function set_next_drow(DtakoRow $next = null)
    {
        if ($next == null) {
            return false;
        }
        foreach ($next->dtako_events as $vv) {
            $this->data->dtako_events[] = $vv;
        }
        # code...
    }

    public function set_last_row()
    {
        $start = $this->data->出庫日時;
        $this->last_row = $this->DtakoRows->find()
            ->where(['車輌CD' => $this->data->車輌CD, '乗務員CD1' => $this->data->乗務員CD1, '対象乗務員区分' => 1])
            ->where(function (QueryExpression $q) use ($start) {
                return $q->lt('出庫日時', $start);
            })
            ->contain(
                ['DtakoEvents' => function (Query $q) {
                    return $q->where([
                        ' DtakoEvents.イベント名 in ' => ['運行開始', '運転', '休憩', '休息', '積み', '降し', '運行終了'],
                        ['DtakoEvents.非表示 is null ']
                    ])->orderAsc('開始日時');
                }]
            )
            ->contain('DtakoEvents.DtakoEventsDetails')
            ->where(['総走行距離 is not' => 0])
            ->order(['DtakoRows.出庫日時 desc'])->first();
    }

    public function is_next_oroshi_fst(DtakoRow $next = null)
    {
        $flag = 0;
        foreach ($next->dtako_events as $vv) {
            if ($vv->dtako_events_detail == null) {

                if ($vv['イベント名'] == "積み") {
                    if ($flag == 0) {

                        return false;
                    } else {
                        return true;
                    }
                }
                if ($vv['イベント名'] == "降し") {
                    // $flag=1;
                    $this->next_first_oroshi_row = $vv;
                    $this->set_oroshi($vv);
                    // return true;
                }
            }
        }
        // dump(__LINE__);
        // dd($next);
        return $flag;
        // return false;
    }

    /**
     * 前回の運行で、前積みしたか
     */
    public function ck_set_maedumi()
    {
        // dump(__LINE__);
        // dd($this->data);
        $tmpst = null;
        $tmparray = [];
        if ($this->last_row == null) {
            $this->maedumi_flg = false;
            return false;
        }

        foreach ($this->last_row->dtako_events as $vv) {
            if ($vv->dtako_events_detail == null or $vv->dtako_events_detail->備考 == "保留") { // 備考がNullだったら
                if ($vv['イベント名'] == "積み") {
                    $tmparray = $vv;
                    $this->maedumi_flg = true;
                    // dump($vv);
                    // dump(__LINE__);
                }
                if ($vv['イベント名'] == "降し") {
                    $this->maedumi_flg  = false;
                    $tmparray = [];
                }
            }
        }
        if ($this->maedumi_flg) {
            $tmst = $tmparray->開始日時->i18nformat('yMMdd') . '_' . $tmparray->車輌CC . '_' . sprintf('%04d', $tmparray->乗務員CD1);
            if (!array_key_exists($tmst, $this->積)) {
                // $this->積[$tmst][]=$tmparray;
                // dump(__LINE__);
                // dump($this->積);
                $this->積 = array_merge([$tmst => []], $this->積);
                $this->積[$tmst][] = $vv;
                // dump($this->積);
                // dump(__LINE__); 
                // dump("test");
            } else {
                array_unshift($this->積[$tmst], $vv);
            }
            // dd($tmparray);
            $this->maedumi_data = $tmparray;
        }
    }


    /**
     * 卸開始ならばTrue,その他、積卸がない場合や積開始の場合はfalseを設定。
     */
    public function is_oroshi_fst()
    {
        /** */
        // dump(__LINE__);
        // dd($this->data);
        foreach ($this->data->dtako_events as $key => $val) {
            if ($val->イベントCD == 202) {
                return false;
            }
            if ($val->イベントCD == 203) {
                return true;
            }
        }
        return false;
    }

    public function calc_fin()
    {

        // dump($tmptmp);
        // die;
        $tmp_d = [];
        $tmp_k = [];
        $tmp_drive = 0;
        $tmp_kosoku = 0;
        $tmp_kyusoku = 0;

        /**
         * 開始日時を積、卸の状況に合わせて取得
         * 最初の卸が、一番星と紐づいているならば、運行開始から
         * その他の場合、積から開始
         * 
         * 卸の部分は改修要す
         * 
         *  @var time $tmp_st  */

        // dd(array_key_first($this->積));
        // dd("test;"); 
        // dd($this->first_oroshi_row); 
        if ($this->first_tsumi_row == []) {
            return false;
        }
        if ($this->oroshi_fst and $this->first_oroshi_row->得意先 != null) {
            $tmp_st = $this->first_oroshi_row->開始日時;
        } else {

            $tmp_st = $this->first_tsumi_row->開始日時;
        }
        foreach ($this->data->dtako_events as $kk => $vv) {
            // dd($vvv);
            if ($tmp_st == null) {
                $tmp_st = $vv['開始日時'];
            }
            if ($vv['イベントCD'] == 302) {
                if ($vv['区間時間'] >= 480) {
                    $tmp_kyusoku = 600;
                } else {
                    $tmp_kyusoku = $tmp_kyusoku + $vv['区間時間'];
                }
            }
            if ($vv['開始日時'] >= $tmp_st->addHours(24) or $tmp_kyusoku >= 600) {
                if ($tmp_drive >= 540) {
                    foreach ($tmp_d as $kkk1 => $vvv1) {
                        $tmptmp[$vvv1]['unten_o9_time'] = 1;
                    }
                }
                if ($tmp_kosoku >= 900) {
                    foreach ($tmp_k as $kkk1 => $vvv1) {
                        $tmptmp[$vvv1]['kosoku_o15_time'] = 1;
                    }
                }
                $tmp_st = null;
                $tmp_drive = 0;
                $tmp_kosoku = 0;
                $tmp_kyusoku = 0;
                $tmp_d = [];
                $tmp_k = [];
            } else {
                if ($vv['イベントCD'] == 201) { //運転の場合
                    $tmp_d[] = $kk; //idをtmp_dに追加
                    $tmp_drive = $tmp_drive + $vv['区間時間'];
                }
                if ($vv['イベントCD'] !== 302) { //休息以外
                    $tmp_k[] = $kk; //idをtmp_kに追加
                    $tmp_kosoku = $tmp_kosoku + $vv['区間時間'];
                }
            }

            if ($tmp_drive >= 540) { //運転9時間越え
                foreach ($tmp_d as $kkk1 => $vvv1) {
                    $this->data->dtako_events[$vvv1]['unten_o9_time'] = 1;
                }
            }
            if ($tmp_kosoku >= 900) { //拘束時間１５時間
                foreach ($tmp_k as $kkk1 => $vvv1) {
                    $this->data->dtako_events[$vvv1]['kosoku_o15_time'] = 1;
                }
            }
        }
        //保存　削除
        // if (!isset($tmptmp)) {
        //     $tmptmp = null;
        // }
        // if ($tmptmp == null) {
        // } else {
        //     dd($tmptmp);
        $this->DtakoEvents->saveMany($this->data->dtako_events);
        // dd("test");  
        // dd($this->data->dtako_events);
        // }
    }


    public function is_in_oroshi(string $oroshi_date)
    {
        // dump($oroshi_date);
        // dump($this);
        if (array_key_exists($oroshi_date, $this->卸)) {
            return true;
        } else {

            return false;
        }
    }
    public function is_tsumi_end()
    {
        return $this->is_tsumi_end;
    }

    public function set_tsumi(DtakoEvent $vv1 = null)
    {
        # code...
        if ($vv1 == null) {
            return false;
        }
        if ($vv1['イベントCD'] == 202) {
            if ($vv1->dtako_events_detail != null) {
                if ($vv1->dtako_events_detail->備考 != 'error' or $vv1->dtako_events_detail->備考 != '除外') {
                    // continue;
                    return false;
                }
            }
            // $vv['積'][$vv1['開始日時']->i18nformat('yyyyMMdd')] = [];
            // if ($vv1->dtako_events_detail != null and  $vv1->dtako_events_detail->備考 != '除外') {

            $this->積[$vv1['開始日時']->i18nformat('yyyyMMdd') . '_' . $vv1['車輌CC'] . '_' . $vv1['乗務員CD1']][] = $vv1;
            // }
            if (count($this->積) == 1) {
                $this->first_tsumi_row = $vv1;
            }
            $this->last_tsumi_row = $vv1;
            // dd($this->積);
        }
    }

    public function set_oroshi(DtakoEvent $vv1 = null)
    {
        # code...
        if ($vv1 == null) {
            return false;
        }
        $i = 0;
        if ($vv1['イベントCD'] == 203) {
            if (!isset($this->卸[$vv1['開始日時']->i18nformat('yyyyMMdd')])) {
                $this->卸[$vv1['開始日時']->i18nformat('yyyyMMdd')] = [];
                if ($this->first_oroshi_row == null) {
                    $this->first_oroshi_row = $vv1;
                }
            }

            $this->卸[$vv1['開始日時']->i18nformat('yyyyMMdd')][] = $vv1;
            $this->last_oroshi_row = $vv1;
        }
        $this->last_row = $vv1;
    }

    public function set_tsumi_oroshi()
    {
        $this->is_tsumi_end = false;

        /**dtakoRowを検索 */
        // dump($vv);die;
        $this->積 = [];
        $this->卸 = [];

        // dd($this);
        /**イベント毎に検索、積と卸を抽出 */
        foreach ($this->data->dtako_events as $kk1 => $vv1) {
            /**積設定　dtakoRowsデータに、[積]を追加し、開始日時を追加する
             * 備考が除外の場合、[積]に入れない
             */
            $this->set_tsumi($vv1);
            // /**卸設定 卸の場合、同日の積がある場合は当配、その他は複日に設定
            $this->set_oroshi($vv1);

            $this->set_tsumi_end($vv1);
            // dd($vv1);
            // $this->data->dtako_events[$kk1]['検索']=$vv1['乗務員CD1'].$vv1['乗務員CD1'].'_'.$vv1['乗務員CD1'];
            // dd($this->data->dtako_events[$kk1]);
        }
    }
    public function set_tsumi_end(DtakoEvent $vv1 = null)
    {
        if ($vv1 == null) {
            return false;
        }
        if ($vv1['イベントCD'] == 202) {
            $this->is_tsumi_end = true;
        }

        if ($vv1['イベントCD'] == 203) {
            $this->is_tsumi_end = false;
        }
        # code...
    }
}
