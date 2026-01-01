<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Knowledge;
use App\Models\User;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Exception;

class KnowledgeController extends Controller
{
    // Apple ID 共享接口地址
    private $share_url = "https://test.com/shareapi/kfcv50";

    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $knowledge = Knowledge::where('id', $request->input('id'))
                ->where('show', 1)
                ->first()
                ->toArray();

            if (!$knowledge) abort(500, __('Article does not exist'));

            $user = User::find($request->user['id']);
            $userService = new UserService();

            if (!$userService->isAvailable($user)) {
                $this->formatAccessData($knowledge['body']);
            }

            $subscribeUrl = Helper::getSubscribeUrl($user['token']);

            // 原有变量替换
            $knowledge['body'] = str_replace('{{siteName}}', config('v2board.app_name', 'V2Board'), $knowledge['body']);
            $knowledge['body'] = str_replace('{{subscribeUrl}}', $subscribeUrl, $knowledge['body']);
            $knowledge['body'] = str_replace('{{urlEncodeSubscribeUrl}}', urlencode($subscribeUrl), $knowledge['body']);
            $knowledge['body'] = str_replace(
                '{{safeBase64SubscribeUrl}}',
                str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($subscribeUrl)),
                $knowledge['body']
            );
            $knowledge['body'] = str_replace('{{subscribeToken}}', $user['token'], $knowledge['body']);

            // ✅ 注入 Apple ID 共享信息
            $this->apple($knowledge['body']);

            return response([
                'data' => $knowledge
            ]);
        }

        $builder = Knowledge::select(['id', 'category', 'title', 'updated_at'])
            ->where('language', $request->input('language'))
            ->where('show', 1)
            ->orderBy('sort', 'ASC');

        $keyword = $request->input('keyword');
        if ($keyword) {
            $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'LIKE', "%{$keyword}%")
                      ->orWhere('body', 'LIKE', "%{$keyword}%");
            });
        }

        return response([
            'data' => $builder->get()->groupBy('category')
        ]);
    }

    private function getBetween($input, $start, $end)
    {
        $substr = substr(
            $input,
            strlen($start) + strpos($input, $start),
            (strlen($input) - strpos($input, $end)) * (-1)
        );
        return $start . $substr . $end;
    }

    private function formatAccessData(&$body)
    {
        while (strpos($body, '<!--access start-->') !== false) {
            $accessData = $this->getBetween($body, '<!--access start-->', '<!--access end-->');
            if ($accessData) {
                $body = str_replace(
                    $accessData,
                    '<div class="v2board-no-access">' . __('You must have a valid subscription to view content in this area') . '</div>',
                    $body
                );
            }
        }
    }

    /**
     * Apple ID 共享信息注入
     * 模板变量：
     * {{apple_id0}} {{apple_pw0}} {{apple_status0}} {{apple_time0}}
     */
    private function apple(&$body)
    {
        try {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
                'http' => [
                    'timeout' => 5,
                    'header' => [
                        'Content-Type: application/json',
                        'Accept: application/json'
                    ]
                ]
            ]);

            $result = file_get_contents($this->share_url, false, $context);
            if ($result === false) {
                throw new Exception('Apple 共享接口请求失败');
            }

            $data = json_decode($result, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Apple 共享接口返回数据格式错误');
            }

            if (!empty($data['status']) && !empty($data['accounts'])) {
                foreach ($data['accounts'] as $i => $account) {
                    $body = str_replace("{{apple_id{$i}}}", $account['username'] ?? '', $body);
                    $body = str_replace("{{apple_pw{$i}}}", $account['password'] ?? '', $body);
                    $body = str_replace(
                        "{{apple_status{$i}}}",
                        !empty($account['status']) ? '正常' : '异常',
                        $body
                    );
                    $body = str_replace("{{apple_time{$i}}}", $account['last_check'] ?? '', $body);
                }
            } else {
                $body = str_replace('{{apple_id0}}', '暂无可用 Apple ID', $body);
            }
        } catch (Exception $e) {
            $body = str_replace('{{apple_id0}}', $e->getMessage(), $body);
        }
    }
}
