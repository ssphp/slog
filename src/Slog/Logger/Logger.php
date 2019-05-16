<?php

namespace Slog\Logger;

use Slog\Filter\Filter;
use StdLog\AbstractLogger;
use StdLog\LogLevel;
use StdLog\LogType;

/**
 * 记录日志
 *
 * @Author   qishaobo
 *
 * @DateTime 2019-05-15
 */
class Logger extends AbstractLogger
{
    /**
     * 配置文件
     *
     * @var array
     */
    protected static $config = [];

    public function __construct(array $config = [])
    {
        $this->initConfig($config);
    }

    /**
     * 加载配置文件
     *
     * @param    array      $config 配置
     *
     * @return   null
     */
    private function initConfig(array $config = [])
    {
        if (!empty(self::$config)) {
            return;
        }

        $baseConfig = require __DIR__ . '/../../../config/log.php';
        if (empty($config)) {
            self::$config = $baseConfig;
            return;
        }

        foreach ($config as $k => $v) {
            if (!isset($baseConfig[$k])) {
                unset($config[$k]);
            }

            if ($k === 'levels' || $k === 'types') {
                unset($config[$k]);
            }
        }

        $config = array_merge($baseConfig, $config);
        self::$config = $config;
    }

    /**
     * 日志内容包含的基本字段
     *
     * @return   [type]     [description]
     */
    private function baseContent($logType)
    {
        return [
            'logTime' => microtime(true),
            'traceId' => '',
            'logType' => $logType,
        ];
    }

    public function log(array $message, string $logType = 'info', string $level = 'info')
    {
        //检查日志级别
        if (!$this->checkLevel($level)) {
            return [
                'code' => '0x000000',
                'data' => '日志级别不用登记',
            ];
        }

        //检查日志包含字段
        if (!isset(LogType::$$logType)) {
            return [
                'code' => '0x000001',
                'data' => '日志类型不存在',
            ];
        }

        $logFields = LogType::$$logType;
        if (!$this->checkLogParam($message, $logFields)) {
            return [
                'code' => '0x000002',
                'message' => '日志内容缺少必填字段',
            ];
        }

        //记录日志
        $baseContent = $this->baseContent($logType);
        $message = empty($message) ? $baseContent : array_merge($baseContent, $message);

        $fiterObj = new Filter(self::$config);
        $data = $fiterObj->fiter($message);
        $result = $this->writeLog($data);

        return [
            'code' => '0x000000',
        ];
    }

    /**
     * 检查必须包含字段
     *
     * @param  array  $message
     * @param  array  $logType
     *
     * @return bool
     */
    public function checkLogParam(array $message, array $logFields)
    {
        if (empty($message)) {
            return false;
        }

        if (!empty($logFields['extendFields'])) {
            foreach ($logFields['extendFields'] as $v) {
                if (!isset(LogType::$$v)) {
                    continue;
                }

                $logFields = array_merge($logFields, LogType::$$v);
            }

            unset($logFields['extendFields']);
        }

        foreach ($logFields as $field) {
            if (!isset($message[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * 查询日志内容包含字段
     *
     * @param    string     $type
     *
     * @return   array
     */
    public function getFields($type)
    {
        static $logFields;

        if (isset($logFields[$type])) {
            return $logFields[$type];
        }

        if (!isset(self::${$type})) {
            return $logFields[$type] = [];
        }

        $fields = self::${$type};
        if (!empty($extendFields = $fields['extendFields'])) {
            unset($fields['extendFields']);
            foreach ($extendFields as $field) {
                $fieldVal = isset(self::${$field}) ? self::${$field} : [];
                $fields = call_user_func('array_merge', $fields, $fieldVal);
            }
        }

        return $logFields[$type] = $fields;
    }

    /**
     * 检查日志级别
     *
     * @param    string     $logLevel
     *
     * @return   bool
     */
    protected function checkLevel(string $logLevel)
    {
        $settedLevel = isset(self::$config['level']) ? self::$config['level'] : 'info';

        if (!LogLevel::$$settedLevel) {
            throw new Exception("配置了未知的日志级别");
        }

        if (LogLevel::$$logLevel < LogLevel::$$settedLevel) {
            return false;
        }

        return true;
    }

    /**
     * 记录日志
     *
     * @param    array     $data
     *
     * @return   bool
     */
    protected function writeLog(array $data)
    {
        //格式化日志
        if (empty(self::$config['formatter']) || !class_exists('\\Slog\\Formatter\\' . self::$config['formatter'])) {
            $formatterObj = new \Slog\Formatter\Json();
        } else {
            $class = '\\Slog\\Formatter\\' . self::$config['formatter'];
            $formatterObj = new $class();
        }

        $lock = isset(self::$config['lockEx']) && self::$config['lockEx'] ? true : false;
        $file = !empty(self::$config['file']) ? self::$config['file'] : 'log/' . date("Ymd") . '/' . $data['logType'] . '.log';

        //记录日志
        $collectObj = new \Slog\Collect\File($file, $lock);
        $collectObj->write($formatterObj->format($data));
        $collectObj->close();

        return true;
    }
}
