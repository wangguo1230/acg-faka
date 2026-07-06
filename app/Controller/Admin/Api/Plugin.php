<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Consts\Hook;
use App\Controller\Base\API\Manage;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Util\Theme;
use Kernel\Annotation\Interceptor;
use Kernel\Annotation\Post;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor([ManageSession::class], Interceptor::TYPE_API)]
class Plugin extends Manage
{
    /**
     * @return array
     */
    public function getPlugins(): array
    {
        $plugins = \Kernel\Util\Plugin::getPlugins(false);
        $appStore = (array)json_decode((string)file_get_contents(BASE_PATH . "/runtime/plugin/store.cache"), true);
        $path = BASE_PATH . "/app/Plugin/";

        $keywords = urldecode((string)$_POST['keywords']);
        $status = $_POST['equal-status'];

        foreach ($plugins as $key => $plugin) {

            $plugins[$key]["id"] = $plugin[\App\Consts\Plugin::PLUGIN_NAME];
            if (!array_key_exists($plugins[$key]["id"], $appStore)) {
                $plugins[$key]['icon'] = "/favicon.ico";
            } else {
                $plugins[$key]['icon'] = \App\Service\App::APP_URL . $appStore[$plugins[$key]["id"]]['icon'];

                if ($plugin['VERSION'] !== $appStore[$plugin['PLUGIN_NAME']]["version"]) {
                    $plugins[$key]['HAVE_UPDATE'] = true;
                }
            }

            //判断文档是否存在
            if (is_dir($path . $plugins[$key]["id"] . "/Wiki")) {
                $plugins[$key]['wiki'] = "/admin/plugin/wiki?plugin={$plugins[$key]["id"]}";
            }

            if ($status !== "" && $status !== null) {
                //未运行
                if ((int)$plugin[\App\Consts\Plugin::PLUGIN_CONFIG]['STATUS'] != $status) {
                    unset($plugins[$key]);
                }
            }


            if ($keywords) {
                if (!str_contains($plugin[\App\Consts\Plugin::NAME], $keywords) && !str_contains($plugin[\App\Consts\Plugin::DESCRIPTION], $keywords)) {
                    unset($plugins[$key]);
                }
            }
        }

        $plugins = array_values($plugins);

        usort($plugins, function ($a, $b) {
            $aTop = ($a['PLUGIN_CONFIG']['top'] ?? 0) == 1 ? 1 : 0;
            $bTop = ($b['PLUGIN_CONFIG']['top'] ?? 0) == 1 ? 1 : 0;
            return $bTop <=> $aTop;
        });

        usort($plugins, function ($a, $b) {
            return ($b['HAVE_UPDATE'] ?? false) <=> ($a['HAVE_UPDATE'] ?? false);
        });

        return $this->json(200, 'success', ["list" => $plugins]);
    }

    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     * @throws \ReflectionException
     * @throws \Throwable
     */
    public function setConfig(Request $request): array
    {
        $map = $request->post(flags: Filter::NORMAL);

        $id = $request->get("id") ?: $request->post("id");

        if (!$id) {
            throw new JSONException("插件不存在");
        }

        if (isset($map['id'])) {
            unset($map['id']);
        }

        $plugin = \Kernel\Util\Plugin::getPlugin($id, false);

        if (!$plugin) {
            throw new JSONException("插件不存在");
        }
        $config = $plugin[\App\Consts\Plugin::PLUGIN_CONFIG];

        if (isset($map['STATUS'])) {
            $configFile = BASE_PATH . '/app/Plugin/' . $id . '/Config/Config.php';
            if ((int)$config['STATUS'] == 0 && $map['STATUS'] == 1) {
                _plugin_start($id);
                // 回读实际文件校验：_plugin_start 内部若因插件代码错误（如 Hook 注解引用了
                // 不存在的常量）静默失败，STATUS 不会被写入。直接读磁盘字节、绕过 opcache，
                // 确认是否真的启动，避免出现“返回已启动但其实没启用”的假成功。
                if (!self::statusWritten($configFile, 1)) {
                    throw new JSONException("插件启动失败：插件代码可能存在错误（例如 Hook 注解引用了不存在的常量），请检查插件后重试");
                }
                ManageLog::log($this->getManage(), "启动了插件({$id})");
                return $this->json(200, "插件已启动");
            } else if ((int)$config['STATUS'] == 1 && $map['STATUS'] == 0) {
                _plugin_stop($id);
                if (!self::statusWritten($configFile, 0)) {
                    throw new JSONException("插件停止失败，请重试");
                }
                ManageLog::log($this->getManage(), "停止了插件({$id})");
                return $this->json(200, "插件已停止");
            }
        }

        foreach ($map as $k => $v) {
            $config[$k] = is_scalar($v) ? urldecode((string)$v) : $v;
        }

        hook(Hook::ADMIN_API_PLUGIN_SAVE_CONFIG, $id, $map); //12/09-重写HOOK逻辑
        \Kernel\Util\Plugin::runHookState($id, \Kernel\Annotation\Plugin::SAVE_CONFIG, $id, $map);//2022/01/12新增插件保存配置逻辑，无需启用插件也可以hook

        $configFile = BASE_PATH . '/app/Plugin/' . $id . '/Config/Config.php';
        setConfig($config, $configFile);
        ManageLog::log($this->getManage(), "修改了插件({$id})的配置");
        return $this->json(200, '配置已生效');
    }

    /**
     * 直接读取插件 Config.php 的磁盘内容，校验 STATUS 是否已写成期望值。
     * 用 file_get_contents 读原始字节，不经 require/opcache，避免同一请求内读到旧缓存。
     * @param string $configFile
     * @param int $expect
     * @return bool
     */
    private static function statusWritten(string $configFile, int $expect): bool
    {
        $raw = (string)@file_get_contents($configFile);
        if ($raw === '') {
            return false;
        }
        return (bool)preg_match("/['\"]?STATUS['\"]?\\s*=>\\s*['\"]?" . $expect . "['\"]?\\s*,/", $raw);
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function setThemeConfig(): array
    {
        $map = $this->request->post(flags: Filter::NORMAL);
        $id = $this->request->get("id") ?: $this->request->post("id");

        if (!$id) {
            throw new JSONException("模板不存在");
        }

        if (isset($map['id'])) {
            unset($map['id']);
        }

        $theme = Theme::getConfig($id);
        if (empty($theme)) {
            throw new JSONException("模板不存在");
        }
        $config = $theme["setting"];
        foreach ($map as $k => $v) {
            $config[$k] = is_scalar($v) ? urldecode((string)$v) : $v;
        }
        setConfig($config, BASE_PATH . "/app/View/User/Theme/{$id}/Setting.php");
        ManageLog::log($this->getManage(), "修改了主题({$id})的配置");
        return $this->json(200, '模板设置已生效');
    }

    /**
     * 获取插件日志
     * @param string $handle
     * @return array
     */
    public function getPluginLog(#[Post] string $handle): array
    {
        $pluginLog = \App\Util\Plugin::getPluginLog($handle);
        return $this->json(200, 'success', ['log' => $pluginLog]);
    }

    /**
     * @param string $handle
     * @return array
     */
    public function ClearPluginLog(#[Post] string $handle): array
    {
        \App\Util\Plugin::ClearPluginLog($handle);
        ManageLog::log($this->getManage(), "清空了插件({$handle})的运行日志");
        return $this->json(200, 'success');
    }

}