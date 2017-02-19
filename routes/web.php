<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});


use Illuminate\Http\Request;
use LINE\LINEBot;
use LINE\LINEBot\Constant\HTTPHeader;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\Event\MessageEvent;
use LINE\LINEBot\Event\MessageEvent\TextMessage;
use LINE\LINEBot\Event\PostbackEvent;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ConfirmTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\CarouselColumnTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\CarouselTemplateBuilder;
use LINE\LINEBot\TemplateActionBuilder\UriTemplateActionBuilder;
use LINE\LINEBot\TemplateActionBuilder\PostbackTemplateActionBuilder;
use LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder;

use Illuminate\Foundation\Inspiring;

Route::get('inspire/{userId}', function ($userId) {
    $httpClient = new CurlHTTPClient(config('services.botline.access'));
    $bot = new LINEBot($httpClient, [
        'channelSecret' => config('services.botline.secret')
    ]);
    $bot->pushMessage($userId, new TextMessageBuilder(Inspiring::quote()));
});

Route::post('webhook/line', function (Request $req) {
    $httpClient = new CurlHTTPClient(config('services.botline.access'));
    $bot = new LINEBot($httpClient, [
        'channelSecret' => config('services.botline.secret')
    ]);
    $signature = $req->header(HTTPHeader::LINE_SIGNATURE);
    if (empty($signature)) {
        abort(401);
    }
    try {
        $events = $bot->parseEventRequest($req->getContent(), $signature);
    } catch (\Exception $e) {
        logger()->error((string) $e);
        abort(200);
    }

    foreach ($events as $event) {
        $replyMessage = handle($event);
        $bot->replyMessage($event->getReplyToken(), $replyMessage);
    }
    return response('OK', 200);
});

function setProfile($userId)
{
    $httpClient = new CurlHTTPClient(config('services.botline.access'));
    $bot = new LINEBot($httpClient, [
        'channelSecret' => config('services.botline.secret')
    ]);
    $profile = $bot->getProfile($userId);
    cache(['p'.$userId => $profile->getJSONDecodedBody()], 24 * 60);
}

function getProfile($userId)
{
    $cacheKey = 'p' . $userId;
    if (! empty(cache($cacheKey))) {
        return cache($cacheKey);
    }
    $httpClient = new CurlHTTPClient(config('services.botline.access'));
    $bot = new LINEBot($httpClient, [
        'channelSecret' => config('services.botline.secret')
    ]);
    $profile = $bot->getProfile($userId);
    cache([$cacheKey => $profile->getJSONDecodedBody()], 24 * 60);
    return $profile->getJSONDecodedBody();
}

function handle($event)
{
    if ($event instanceof TextMessage) {
        if (isUserWritingQuestion($event)) {
            return buildReceiveQuestionMessage($event);
        } elseif (trim(strtolower($event->getText())) == 'help') {
            return buildStarterMessage($event);
        } elseif ($event->getText() == 'campaign') {
            return buildCampaignMessage();
        } else {
            return buildCleverbotMessage($event);
        }
    } elseif ($event instanceof PostbackEvent) {
        return handlePostbackMessage($event);
    } else {
        return buildErrorMessage();
    }
}

function buildReceiveQuestionMessage(TextMessage $event)
{
    $cacheKey = 'question'.$event->getUserId();
    if (trim(strtolower($event->getText())) == 'done') {
        return new TemplateMessageBuilder(
            'Confirm',
            new ConfirmTemplateBuilder('Are you done？', [
                new MessageTemplateActionBuilder('Yes', 'Yes! No more question!'),
                new MessageTemplateActionBuilder('No', 'No!'),
            ])
        );
    } elseif (isUserDoneQuestion($event)) {
        logger()->info('Question from User:' . $event->getUserId());
        logger()->info(cache()->pull($cacheKey));
        return new TextMessageBuilder('Your question has been sent!');
    } else {
        $question = cache($cacheKey) . '\n' . $event->getText();
        cache([$cacheKey => $question], 24 * 60);
        return new TextMessageBuilder('Keep writing!');
    }
}

function isUserWritingQuestion(TextMessage $event)
{
    return ! empty(cache('question'.$event->getUserId()));
}

function isUserDoneQuestion(TextMessage $event)
{
    return isUserWritingQuestion($event) && $event->getText() == 'Yes! No more question!';
}

function buildStarterMessage($event)
{
    $profile = getProfile($event->getUserId());
    return new TemplateMessageBuilder('Welcome', new ButtonTemplateBuilder(
        'Welcome',
        'Hi ' . $profile['displayName'] . '. How can i help you?',
        'https://img.gendama.jp/img/renew/common/morimori_anime_on.gif',
        [
            new UriTemplateActionBuilder('Go to Gendama', 'https://www.gendama.jp/sp'),
            new PostbackTemplateActionBuilder('お問い合わせ !', 'action=question'),
            new UriTemplateActionBuilder('電話する!', 'tel:08068919275'),
            new MessageTemplateActionBuilder('Get ready!', 'こんにちは!')
        ]
    ));
}

function handlePostbackMessage(PostbackEvent $event)
{
    parse_str($event->getPostbackData(), $output);
    $action = array_get($output, 'action');
    switch ($action) {
        case 'question':
            $replyMessage = buildQuestionMessage($event);
            break;
        default:
            $replyMessage = buildStarterMessage($event);
            break;
    }
    return $replyMessage;
}

function buildQuestionMessage(PostbackEvent $event)
{
    $cacheKey = 'question'.$event->getUserId();
    cache([$cacheKey => 'Question begin!'], 24 * 60);
    return new TextMessageBuilder('What can i help you?');
}

function buildCleverbotMessage(TextMessage $event)
{
    logger('Get text: ' . $event->getText());
    $userId = $event->getUserId();
    $prev = cache('cleverbot' . $userId);
    logger('cleverbot:' . array_get($prev, 'cs'));
    $cleverbot = resolve('cleverbot');
    $reply = $cleverbot->request($event->getText(), $prev);
    cache([$userId => $reply], 24 * 60);
    return new TextMessageBuilder($reply['output']);
}

function buildCampaignMessage()
{
    return new TemplateMessageBuilder('Welcome', new CarouselTemplateBuilder([
        new CarouselColumnTemplateBuilder(
            'ほけんの時間の＜無料＞保険相談',
            'WEB申込後、無料面談完了',
            'https://img.gendama.jp/service/campaign/jack/sp/dt_img_main20170217_174210ほけんの時間80000PT⇒112000PTクリック無し.png',
            [
                new UriTemplateActionBuilder('Go now', 'https://www.gendama.jp/cl/?id=342840&rt=s&frame=spjack')
            ]
        ),
        new CarouselColumnTemplateBuilder(
            'ほけんの時間の＜無料＞保険相談',
            'WEB申込後、無料面談完了',
            'https://img.gendama.jp/service/campaign/jack/sp/dt_img_main20170217_174210ほけんの時間80000PT⇒112000PTクリック無し.png',
            [
                new UriTemplateActionBuilder('Go now', 'https://www.gendama.jp/cl/?id=342840&rt=s&frame=spjack')
            ]
        ),
        new CarouselColumnTemplateBuilder(
            'ほけんの時間の＜無料＞保険相談',
            'WEB申込後、無料面談完了',
            'https://img.gendama.jp/service/campaign/jack/sp/dt_img_main20170217_174210ほけんの時間80000PT⇒112000PTクリック無し.png',
            [
                new UriTemplateActionBuilder('Go now', 'https://www.gendama.jp/cl/?id=342840&rt=s&frame=spjack')
            ]
        ),
        new CarouselColumnTemplateBuilder(
            'ほけんの時間の＜無料＞保険相談',
            'WEB申込後、無料面談完了',
            'https://img.gendama.jp/service/campaign/jack/sp/dt_img_main20170217_174210ほけんの時間80000PT⇒112000PTクリック無し.png',
            [
                new UriTemplateActionBuilder('Go now', 'https://www.gendama.jp/cl/?id=342840&rt=s&frame=spjack')
            ]
        ),
        new CarouselColumnTemplateBuilder(
            'ほけんの時間の＜無料＞保険相談',
            'WEB申込後、無料面談完了',
            'https://img.gendama.jp/service/campaign/jack/sp/dt_img_main20170217_174210ほけんの時間80000PT⇒112000PTクリック無し.png',
            [
                new UriTemplateActionBuilder('Go now', 'https://www.gendama.jp/cl/?id=342840&rt=s&frame=spjack')
            ]
        )
    ]));
}

function buildErrorMessage()
{
    return new TextMessageBuilder('What does this mean?');
}
