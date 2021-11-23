<?php

namespace Encore\ChunkFileUpload\Http\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Qiniu\Auth;

/**
 * Class ChunkFileUploadController
 * @package Encore\ChunkFileUpload\Http\Controllers
 */
class ChunkFileUploadController extends Controller
{
    /**
     * 获取七牛token
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getQiniuToken(Request $request)
    {
        try {
            $config = config('chunk_file_upload.disks.' . $request->disk);
            if (!$config) {
                throw new \Exception ('未找到该公共磁盘信息！');
            }
            $accessKey = $config['access_key'];
            $secretKey = $config['secret_key'];
            $bucketName = $config['bucket'];
            $auth = new Auth($accessKey, $secretKey);
            $token = $auth->uploadToken($bucketName);
            return response()->json(['code' => 200, 'uptoken' => $token]);
        } catch (\Exception $exception) {
            return response()->json(['code' => 0, 'msg' => $exception->getMessage()]);
        }
    }

    /**
     * 上传小于规定大小的文件
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request)
    {
        $path = Storage::disk($request->disk)->putFileAs(
            'large_files/' . date('Y/m/d', time()), $request->file('file'), $request->key
        );
        return $this->returnResult($path);
    }

    /**
     * 上传块
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadMkblk(Request $request)
    {
        try {
            $disk = config('chunk_file_upload.default.disk');
            // 接收相关数据
            $file = file_get_contents('php://input');
            $file_name = substr($request->key, 0, strrpos($request->key, "."));
            $dir = 'chunk_file_upload/' . Admin::user()->id . $request->id . '-' . $file_name;
            $path = $dir . '/' . $request->chunk;
            Storage::disk(config('chunk_file_upload.default.disk'))->put($path, $file);
            return response()->json([
                'checksum' => md5('666'),
                'crc32' => time(),
                'ctx' => base64_encode(md5('666')),
                'expired_at' => time() + 10000,
                'host' => $request->route()->domain(),
                'offset' => time()
            ]);
        } catch (\Exception $exception) {
            return response()->json(['code' => 0, 'msg' => $exception->getMessage()]);
        }
    }

    /**
     * @param Request $request
     * @param $file_size
     * @param $file_name
     * @return \Illuminate\Http\JsonResponse
     */
    public function mkFileByKey(Request $request, $file_size, $file_name)
    {
        $data = str_replace(array('-', '_'), array('+', '/'), $file_name);

        $mod4 = strlen($data) % 4;

        if ($mod4) {
            $data .= substr('====', $mod4);
        }
        $file_name = base64_decode($data);
        Log::info($file_name);
        return $this->mergingBlock($request, $file_name);
    }

    /**
     * @param Request $request
     * @param $file_size
     * @return \Illuminate\Http\JsonResponse
     */
    public function mkFileRand(Request $request, $file_size)
    {
        $file_name = time() . substr(md5(time()), 0, 11) . '.' . $request->header('file-ext');
        return $this->mergingBlock($request, $file_name);
    }

    /**
     * 合并块成一个文件
     * @param $request
     * @param $file_name
     * @return \Illuminate\Http\JsonResponse
     */
    public function mergingBlock($request, $file_name)
    {
        $disk = config('chunk_file_upload.default.disk');
        $f_name = substr($file_name, 0, strrpos($file_name, "."));
        // 找出分片文件
        $dir = public_path('storage') . '/chunk_file_upload/' . Admin::user()->id . $request->header('file-id') . '-' . $f_name;
        // 获取分片文件内容
        $block_info = scandir($dir, 0);
        // 除去无用文件
        foreach ($block_info as $key => $block) {
            if ($block == '.' || $block == '..')
                unset($block_info[$key]);
        }
        // 数组按照正常规则排序
        natsort($block_info);

        $time = 'large_files/' . date('Y/m/d', time());
        // 定义保存文件
        if (config('chunk_file_upload.disks.' . $disk)) {//有
            $save_dir = config('chunk_file_upload.disks.' . $disk . '.root') . '/' . $time;
        } else {//没有，用默认
            $save_dir = config('chunk_file_upload.disks.public.root') . '/' . $time;
            $disk = 'public';
        }
        //先创建一个空文件
        Storage::disk($disk)->put($time . '/' . $file_name, '');

        $save_file = $save_dir . '/' . $file_name;
        // 开始写入
        $out = fopen($save_file, "wb");
        // 增加文件锁
        if (flock($out, LOCK_EX)) {
            foreach ($block_info as $b) {
                // 读取文件
                if (!$in = @fopen($dir . '/' . $b, "rb")) {
                    break;
                }
                // 写入文件
                while ($buff = fread($in, 4096)) {
                    fwrite($out, $buff);
                }
                @fclose($in);
                @unlink($dir . '/' . $b);
            }
            flock($out, LOCK_UN);
        }
        @fclose($out);
        @rmdir($dir);
        //然后删除那个文件夹
        $this->removeDir($dir);
        return $this->returnResult($time . '/' . $file_name);
    }

    /**
     * @param $dir
     */
    private function removeDir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        //先删除文件
        $block_info = scandir($dir, 0);
        // 除去无用文件
        foreach ($block_info as $key => $block) {
            if ($block == '.' || $block == '..') {
                continue;
            }
            unlink($dir . '/' . $block);
        }
        rmdir($dir);
    }

    /**
     * @param $path
     * @return \Illuminate\Http\JsonResponse
     */
    private function returnResult($path)
    {
        return response()->json([
            'hash' => md5($path),
            'key' => $path
        ]);
    }

    public function getFileinfo(Request $request)
    {
        try {
            $response = [];
            $file_json = $request->input('file_json', '');
            $file_list = json_decode($file_json, true);
            $disk = config('chunk_file_upload.default.disk');
            if (count($file_list) > 0) {
                foreach ($file_list as $k => $value) {
                    $fileInfo = new \SplFileInfo(Storage::disk($disk)->path($value));
                    $response[] = [
                        'name' => $fileInfo->getBasename(),
                        'size' => $fileInfo->getSize(),
                        'lastModifiedDate' => $fileInfo->getMTime(),
                        'ext' => $fileInfo->getExtension(),
                        'file' => $value,
                    ];
                    unset($fileInfo);
                }
            }
            return response()->json($response);
        } catch (\Exception $exception) {
            return response()->json(['code' => 0, 'msg' => $exception->getMessage()]);
        }
    }
}
