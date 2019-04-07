<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BotController extends Controller
{

    /**
     * The verification token for Facebook
     *
     * @var string
     */
    protected $token;

    public function __construct()
    {
        $this->token = env('BOT_VERIFY_TOKEN');
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

        $textMessage = $this->textProcess($sender, $message);

        $re = '請輸入您的 Email 及 LoginID，如：coffee@hgiga.com / coffee。';

        if ($textMessage == 'SUCCESS') {

            $re = '請連入桓基電子郵件帳號密碼驗證頁，以確認是您本人啟用 OTP 驗證服務。驗證網頁：https://192.168.7.181/EIP/cc_opt_auth.php?SocialType=FB';
        } elseif ($textMessage == 'ERROR_EMAIL') {

            $re = "Email 輸入的格式錯誤\n\n請輸入您的 Email 及 LoginID，如：coffee@hgiga.com / coffee。";
        } elseif ($textMessage == 'SUCCESS_MSG') { }


        $this->dispatchResponse($sender, $re . ' ' . $sender);

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
        $access_token = env('BOT_PAGE_ACCESS_TOKEN');
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

        // return $result;
    }


    /**
     * text process, email / login_id
     *
     * @param [type] $sender
     * @param [type] $text
     * @return void
     */
    protected function textProcess($sender, $text)
    {

        if (strpos($text, '/') !== false) {
            $msg = explode('/', $text);

            $msg[0] = trim($msg[0]);
            $msg[1] = trim($msg[1]);

            if (!filter_var($msg[0], FILTER_VALIDATE_EMAIL)) {
                return 'ERROR_EMAIL';
            }

            $userDB = DB::table('users')->where('sender_id', '=', $sender)->get();

            if(count($userDB) == 0) {

                DB::table('users')->insert(
                    [
                        'sender_id' => $sender,
                        'email' => $msg[0],
                        'login_id' => $msg[1],
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                );
            }

            if(count($userDB) > 0 && $userDB[0]->verify == 0){
                DB::table('users')
                    ->where('sender_id', $sender)
                    ->update(
                    [
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


    /**
     * Facebook response information
     *
     * @param Request $request
     * @return void
     */
    public function FacebookAccount(Request $request)
    {

        $user = DB::table('users')
                    // ->where('sender_id', '=', $request['sender_id'])
                    ->where('login_id', '=', $request['account'])
                    ->get();



        if(count($user) == 0){

            $response = response()->json(
                [
                    'code' => 0,
                    'status' => 'REQUEST_ERROR',
                    'data' => ''
                ]
            );

        } else {

            $response = response()->json(
                [
                    'code' => 1,
                    'status' => 'SUCCESS',
                    'data' => [
                        'userId'=> $user[0]->login_id,
                        'sender_id' => $user[0]->sender_id
                    ]
                ]
            );


            DB::table('users')
                    ->where('sender_id', $user[0]->sender_id)
                    ->update(
                    [
                        'verify' => 1
                    ]
                );
        }

        return $response;
    }


    /**
     * Facebook Callback
     *
     * @param Request $request
     * @return void
     */
    public function FacebookCallback(Request $request){

        $user = DB::table('users')
            ->where('sender_id', '=', $request['userid'])
            ->get();



        $sender_id = $user[0]->sender_id;


        $re = "密碼：" . $request['message'];

        $this->dispatchResponse($sender_id, $re);

        return "<script>window.close();</script>";
    }
}
