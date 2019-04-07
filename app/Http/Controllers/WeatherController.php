<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WeatherController extends Controller {

    /**
     * The verification token for Facebook
     *
     * @var string
     */
    protected $token;

    public function __construct()
    {
        $this->token = env('WEATHER_VERIFY_TOKEN');
    }

    /**
     * Verify the token from Messenger. This helps verify your bot.
     *
     * @param  Request $request
     * @return \Illuminate\Http\Response|\Laravel\Lumen\Http\ResponseFactory
     */
    public function verify_token(Request $request)
    {
        $mode  = $request->get('hub_mode');
        $token = $request->get('hub_verify_token');

        if ($mode === "subscribe" && $this->token and $token === $this->token) {
            return response($request->get('hub_challenge'));
        }

        return response("Invalid token!", 400);
    }

    /**
     * Handle the query sent to the bot.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Laravel\Lumen\Http\ResponseFactory
     */
    public function handle_query(Request $request)
    {
        $entry = $request->get('entry');

        $sender  = array_get($entry, '0.messaging.0.sender.id');
        $message = array_get($entry, '0.messaging.0.message.text');


        $response = $this->weatherWitAI($message);

        $resText = $response['_text'];

        $resEntities = $response['entities'];

        if(!empty($resEntities['greetings'][0]['value'])){
            if($resEntities['greetings'][0]['value'] == true){
                $re = '你好，有什麼可以協助你？';
            }
        }

        if(!empty($resEntities['location'][0]['value'])){
            $re =  $resEntities['location'][0]['value'];
        }

        // city
        $city_array = [
            "宜蘭縣", "花蓮縣", "臺東縣",
            "澎湖縣", "金門縣", "連江縣",
            "臺北市", "新北市", "桃園市",
            "臺中市", "臺南市", "高雄市",
            "基隆市", "新竹縣", "新竹市",
            "苗栗縣", "彰化縣", "南投縣",
            "雲林縣", "嘉義縣", "嘉義市",
            "屏東縣", "台南市", "台東縣", "台中市", "台北市"
        ];

        $city = '';

        foreach($city_array as $val){
            if(strpos($resText, mb_substr($val, 0, 2)) !== false) {
                $city = $val;
            }
        }

        if(!empty($city) || strpos($resText, '天氣') !== false || strpos($resText, '氣溫') !== false){

            $re = $this->weatherText($city);

        }

        if(!empty($resEntities['default_city'][0]['value'])){
            $re = '您好，你預設的地點是新竹市';
        }
        if(!empty($resEntities['do_what'][0]['value'])){
            $re = "您好，這是聊天機器人，負責天氣預報，你可以問我「天氣如何？」、「新竹市天氣怎麼樣」之類的話題";
        }


        if(empty($re)){
            $re = "您好，我不太瞭解您所提出的問題！";
        }

        // $textMessage = $this->textProcess($sender, $message);

        // $re = '請輸入您的 Email 及 LoginID，如：coffee@hgiga.com / coffee。';

        // if($textMessage == 'SUCCESS'){
        //     $re = '請連入桓基電子郵件帳號密碼驗證頁，以確認是您本人啟用 OTP 驗證服務。驗證網頁：https://FQDN/otp.php。';
        // } elseif($textMessage == 'ERROR_EMAIL') {
        //     $re = "Email 輸入的格式錯誤\n\n請輸入您的 Email 及 LoginID，如：coffee@hgiga.com / coffee。";
        // }

        // if($textMessage == 'SUCCESS_MSG'){
        //     $re = '歡迎您加入桓基電子郵件OTP認證系統。';
        // }




        $this->dispatchResponse($sender, $re);

        return response('', 200);
    }

    /**
     * Post a message to the Facebook messenger API.
     *
     * @param  integer $id
     * @param  string  $response
     * @return bool
     */
    protected function dispatchResponse($id, $response)
    {
        $access_token = env('WEATHER_PAGE_ACCESS_TOKEN');
        $url = "https://graph.facebook.com/v3.2/me/messages?access_token={$access_token}";

        $data = json_encode([
            'recipient' => ['id' => $id],
            'message'   => ['text' => $response]
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $result = curl_exec($ch);
        curl_close($ch);

        return $result;
    }



    protected function textProcess($sender, $text){

        if(strpos($text, '/') !== false){
            $msg = explode('/', $text);

            $msg[0] = trim($msg[0]);
            $msg[1] = trim($msg[1]);

            if (!filter_var($msg[0], FILTER_VALIDATE_EMAIL)) {
                return 'ERROR_EMAIL';
            }

            $userDB = DB::table('users')->where('sender_id', '=', $sender)->get();

            if(count($userDB) == 0){

                DB::table('users')->insert(
                    ['sender_id' => $sender,
                     'email' => $msg[0],
                     'login_id' => $msg[1],
                     'created_at' => date('Y-m-d H:i:s')
                     ]
                );

            }

            return 'SUCCESS';

        } else {
            return 'SUCCESS_MSG';
        }

        return 'EXIST';

    }


    public function test(){

        $text = 'test@test.com / test';
        $sender = '123123123';

        if(strpos($text, '/') !== false){
            $msg = explode('/', $text);

            $msg[0] = trim($msg[0]);
            $msg[1] = trim($msg[1]);

            $userDB = DB::table('users')->where('sender_id', '=', $sender)->get();

            if(count($userDB) == 0){

                DB::table('users')->insert(
                    ['sender_id' => $sender, 'created_at' => date('Y-m-d H:i:s')]
                );

            }

            // return true;

        }

        // return false;

    }

    /**
     * 天氣預報的 wit.ai
     *
     * @param [type] $messageText
     * @return void
     */
    public function weatherWitAI($messageText){

        $messageText = str_replace(' ', "%20", $messageText);

        $witToken = 'OT4PEHBFGEAJ7CHLUBWNA22WK7EQXDI5';
        $wit_url = "https://api.wit.ai/message?v=" . date('Ymd') . "&q=".$messageText;
        $wit_auth = array("Authorization: Bearer ". $witToken);
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL, $wit_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $wit_auth);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $response = json_decode($response, true);

        return $response;
    }


    public function weatherText($city = ''){
        $weather_token = 'CWB-395E8E5F-77A4-4636-9D86-A450FDCA6E41';

        $handle = fopen('log.txt', 'a+');
        fwrite($handle, $city . "\n");
        fclose($handle);

        $city = str_replace('台', '臺', $city);

        if($city == ''){
            $city = '新竹市';
        }


        $ch = curl_init();

        curl_setopt(
            $ch,
            CURLOPT_URL,
            "https://opendata.cwb.gov.tw/api/v1/rest/datastore/F-C0032-001" .
                "?locationName=" . $city
        );
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: ' . $weather_token
            //'Authorization: Bearer '. TOKEN
        ));

        $result = curl_exec($ch);
        curl_close($ch);

        $handle = fopen('log.txt', 'a+');
        fwrite($handle, $city . " 123\n");
        fclose($handle);

        $result = json_decode($result);

        $parse_name = $result->records->location[0]->locationName;

        // wx_data 一般情況
        $parse_wx = $result->records->location[0]->weatherElement[0]->time;
        $wx_data = array();
        foreach ($parse_wx as $row) {
            $sDate = date('Y-m-d H:i', strtotime($row->startTime));
            $eDate = date('Y-m-d H:i', strtotime($row->endTime));
            $parameter = $row->parameter->parameterName;
            $descr = '';
            if ($sDate == date('Y-m-d 06:00')) {
                $descr = '今日白天';
            } elseif ($sDate == date('Y-m-d 12:00')) {
                $descr = '今日下午';
            } elseif ($sDate == date('Y-m-d 18:00')) {
                $descr = '今晚至明晨';
            } else {
                $descr = '明日白天';
            }
            $wx_data[] = array(
                'startTime' => $sDate,
                'endTime' => $eDate,
                'descr' => $descr,
                'parameter' => $parameter
            );
        }
        // PoP 下雨機率
        $parse_pop = $result->records->location[0]->weatherElement[1]->time;
        $pop_data = array();
        foreach ($parse_pop as $row) {
            $sDate = date('Y-m-d H:i', strtotime($row->startTime));
            $eDate = date('Y-m-d H:i', strtotime($row->endTime));
            $parameter = $row->parameter->parameterName;
            $parameterUnit = $row->parameter->parameterUnit;
            $pop_data[] = array(
                'startTime' => $sDate,
                'endTime' => $eDate,
                'parameter' => $parameter,
                'parameterUnit' => $parameterUnit,
            );
        }
        // 最低溫度
        $parse_mint = $result->records->location[0]->weatherElement[2]->time;
        $mint_data = array();
        foreach ($parse_mint as $row) {
            $sDate = date('Y-m-d H:i', strtotime($row->startTime));
            $eDate = date('Y-m-d H:i', strtotime($row->endTime));
            $parameter = $row->parameter->parameterName;
            $parameterUnit = $row->parameter->parameterUnit;
            $mint_data[] = array(
                'startTime' => $sDate,
                'endTime' => $eDate,
                'parameter' => $parameter,
                'parameterUnit' => $parameterUnit,
            );
        }
        // 最大溫度
        $parse_maxt = $result->records->location[0]->weatherElement[4]->time;
        $maxt_data = array();
        foreach ($parse_maxt as $row) {
            $sDate = date('Y-m-d H:i', strtotime($row->startTime));
            $eDate = date('Y-m-d H:i', strtotime($row->endTime));
            $parameter = $row->parameter->parameterName;
            $parameterUnit = $row->parameter->parameterUnit;
            $maxt_data[] = array(
                'startTime' => $sDate,
                'endTime' => $eDate,
                'parameter' => $parameter,
                'parameterUnit' => $parameterUnit,
            );
        }

        $currentDate = date('Y-m-d H:i');
        $currentIndex = 0;
        foreach ($wx_data as $key => $row) {
            if ($row['startTime'] >= $currentDate && $row['endTime'] <= $currentDate) {
                $currentIndex = $key;
            }
        }

        $weather_date = $wx_data[$currentIndex]['startTime'] . '~' . $wx_data[$currentIndex]['endTime'];
        $weather_dateDescr = $wx_data[$currentIndex]['descr'];
        $weather_forecast = $wx_data[$currentIndex]['parameter'];
        $weather_precipitation = "降雨機率：" . $pop_data[$currentIndex]['parameter'] . "%";
        $weather_maxt = "最高溫：" . $maxt_data[$currentIndex]['parameter'] .  "℃";
        $weather_mint = "最低溫：" . $mint_data[$currentIndex]['parameter'] .  "℃";

        $data = [
            'wather_name' => $parse_name,
            'weather_dateDescr' => $weather_dateDescr,
            'weather_date' => $weather_date,
            'weather_forecast' => $weather_forecast,
            'weather_precipitation' => $weather_precipitation,
            'weather_maxt' => $weather_maxt,
            'weather_mint' => $weather_mint,
        ];


        $answer = implode("\n", $data);

        return $answer;
    }
}
