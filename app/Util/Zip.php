<?php
declare(strict_types=1);

namespace App\Util;


use Kernel\Exception\JSONException;
use Rah\Danpu\Exception;

class Zip
{

    /**
     * @param $filePath
     * @param $path
     * @return bool
     * @throws \Kernel\Exception\JSONException
     */
    public static function unzip($filePath, $path): bool
    {
        try {
            if (empty($path) || empty($filePath)) {
                return false;
            }
            $zip = new \ZipArchive();
            if ($zip->open($filePath) === true) {
                //Zip-Slip 防护：extractTo 会照条目名写盘，含 ../ 或绝对路径的条目可越出 $path
                //（如 ../../public/shell.php 落到 webroot）。解压前逐条校验，命中即整体拒绝。
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    if ($entry === false) {
                        $zip->close();
                        return false;
                    }
                    $normalized = str_replace('\\', '/', $entry);
                    if (str_starts_with($normalized, '/')
                        || preg_match('#^[A-Za-z]:#', $normalized)
                        || $normalized === '..'
                        || str_starts_with($normalized, '../')
                        || str_contains($normalized, '/../')
                        || str_ends_with($normalized, '/..')) {
                        $zip->close();
                        return false;
                    }
                }
                $result = $zip->extractTo($path);
                $zip->close();
                return $result;
            }
            return false;
        } catch (Exception $e) {
            throw new JSONException("解压缩失败，请安装php-zip扩展！");
        }
    }
}