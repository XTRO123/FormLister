<?php namespace FormLister;

use Helpers\Config;
use Helpers\FS;
use Helpers\Lexicon;
use Helpers\Debug;

include_once(MODX_BASE_PATH . 'assets/lib/APIHelpers.class.php');
include_once(MODX_BASE_PATH . 'assets/lib/Helpers/FS.php');
include_once(MODX_BASE_PATH . 'assets/lib/Helpers/Config.php');
require_once(MODX_BASE_PATH . "assets/snippets/DocLister/lib/DLTemplate.class.php");
include_once(MODX_BASE_PATH . "assets/snippets/FormLister/lib/Lexicon.php");

/**
 * Class FormLister
 * @package FormLister
 */
abstract class Core
{
    /**
     * @var array
     * Массив $_REQUEST
     */
    protected $_rq = array();

    protected $modx = null;
    /**
     * @var FS $fs
     */
    public $fs = null;

    public $debug = null;

    /**
     * Идентификатор формы
     * @var mixed|string
     */
    protected $formid = '';

    public $config = null;

    /**
     * Шаблон для вывода по правилам DocLister
     * @var string
     */
    public $renderTpl = '';

    /**
     * Данные формы
     * fields - значения полей
     * errors - ошибки (поле => сообщение)
     * messages - сообщения
     * status - для api-режима, результат использования формы
     * @var array
     */
    private $formData = array(
        'fields'   => array(),
        'errors'   => array(),
        'messages' => array(),
        'files'    => array(),
        'status'   => false
    );

    /**
     * Разрешает обработку формы
     * @var bool
     */
    private $valid = true;

    protected $validator = null;

    /**
     * Массив с правилами валидации полей
     * @var array
     */
    protected $rules = array();

    /**
     * Массив с именами полей, которые можно отправлять в форме
     * По умолчанию все поля разрешены
     * @var array
     */
    public $allowedFields = array();

    /**
     * Значения для пустых элементов управления, например чекбоксов
     * @var array
     */
    public $forbiddenFields = array();

    protected $placeholders = array();

    protected $emptyFormControls = array();

    /**
     * @var Lexicon|null
     */
    protected $lexicon = null;

    public $captcha = null;


    /**
     * Core constructor.
     * @param \DocumentParser $modx
     * @param array $cfg
     */
    public function __construct(\DocumentParser $modx, $cfg = array())
    {
        $this->modx = $modx;
        $this->config = new Config();
        $this->fs = FS::getInstance();
        if (isset($cfg['config'])) {
            $this->config->loadConfig($cfg['config']);
        }
        $this->config->setConfig($cfg);
        if (isset($cfg['debug'])) {
            include_once(MODX_BASE_PATH . 'assets/snippets/FormLister/lib/Debug.php');
            $this->debug = new Debug($modx, array(
                'caller' => 'FormLister\\\\' . $cfg['controller']
            ));
        }
        $this->lexicon = new Lexicon($modx, array(
            'langDir' => 'assets/snippets/FormLister/core/lang/'
        ));
        $this->formid = $this->getCFGDef('formid');
        switch (strtolower($this->getCFGDef('formMethod', 'post'))) {
            case 'post':
                $this->_rq = $_POST;
                break;
            case 'get':
                $this->_rq = $_GET;
                break;
            default:
                $this->_rq = $_REQUEST;
        }
    }

    /**
     * Установка значений в formData, загрузка пользовательских лексиконов
     * Установка шаблона формы
     * Загрузка капчи
     */
    public function initForm()
    {
        $lexicon = $this->getCFGDef('lexicon');
        if ($lexicon) {
            $_lexicon = $this->config->loadArray($lexicon);
            if (is_array($_lexicon)) {
                $lang = $this->lexicon->fromArray($_lexicon);
            } else {
                $lang = $this->lexicon->loadLang($lexicon, $this->getCFGDef('lang'),
                    $this->getCFGDef('langDir'));
            }
            if ($lang) {
                $this->log('Custom lexicon loaded', array('lexicon' => $lang));
            }
        }
        $this->allowedFields = array_merge($this->allowedFields,
            $this->config->loadArray($this->getCFGDef('allowedFields')));
        $this->forbiddenFields = array_merge($this->forbiddenFields,
            $this->config->loadArray($this->getCFGDef('forbiddenFields')));
        $this->emptyFormControls = array_merge($this->emptyFormControls,
            $this->config->loadArray($this->getCFGDef('emptyFormControls'), ''));
        $this->setRequestParams();
        $this->setExternalFields($this->getCFGDef('defaultsSources', 'array'));
        $this->renderTpl = $this->getCFGDef('formTpl'); //Шаблон по умолчанию
        $this->initCaptcha();
        $this->runPrepare('prepare');

        return $this;
    }

    /**
     * Загружает в formData данные не из формы
     * @param string $sources список источников
     * @param string $arrayParam название параметра с данными
     * @return $this
     */
    public function setExternalFields($sources = 'array', $arrayParam = 'defaults')
    {
        $keepDefaults = $this->getCFGDef('keepDefaults', 0);
        $submitted = $this->isSubmitted();
        if ($submitted && !$keepDefaults) {
            return $this;
        }
        $sources = array_filter($this->config->loadArray($sources, ';'));
        $prefix = '';
        foreach ($sources as $source) {
            $fields = array();
            $_source = explode(':', $source);
            switch ($_source[0]) {
                //Массив значений указывается в параметре defaults
                case 'array':
                    if ($arrayParam) {
                        $fields = $this->config->loadArray($this->getCFGDef('defaults'));
                    }
                    break;
                //Массив значений указывается в произвольном параметре
                case 'param': {
                    if (!empty($_source[1])) {
                        $fields = $this->config->loadArray($this->getCFGDef($_source[1]));
                        if (isset($_source[2])) {
                            $prefix = $_source[2];
                        }
                    }
                    break;
                }
                //Массив значений указывается в параметре сессии
                case 'session':
                    if (!empty($_source[1]) && isset($_SESSION[$_source[1]]) && is_array($_SESSION[$_source[1]])) {
                        $fields = $_SESSION[$_source[1]];
                        if (isset($_source[2])) {
                            $prefix = $_source[2];
                        }
                    }
                    break;
                //Значение поля берется из плейсхолдера MODX
                case 'plh':
                    if (!empty($_source[1])) {
                        $fields = array();
                        $keys = explode(',', $source[1]);
                        foreach ($keys as $key) {
                            if (isset($this->modx->placeholders[$key])) {
                                $fields[$key] = $this->modx->placeholders[$key];
                            }
                        }
                        if (isset($_source[2])) {
                            $prefix = $_source[2];
                        }
                    }
                    break;
                //Массив значений берется из плейсхолдера MODX
                case 'aplh':
                    if (!empty($_source[1]) && isset($this->modx->placeholders[$_source[1]]) && is_array($this->modx->placeholders[$_source[1]])) {
                        $fields = $this->modx->placeholders[$_source[1]];
                        if (isset($_source[2])) {
                            $prefix = $_source[2];
                        }
                    }
                    break;
                //Загружает в форму массив конфигурации MODX
                case 'config':
                    $fields = $this->modx->config;
                    if (isset($_source[1])) {
                        $prefix = $_source[1];
                    }
                    break;
                //Загружает значения из кук (перечисляются через запятую)
                case 'cookie':
                    if (!empty($_source[1])) {
                        $fields = array();
                        $keys = explode(',', $source[1]);
                        foreach ($keys as $key) {
                            if (isset($_COOKIE[$key])) {
                                $fields[$key] = $_COOKIE[$key];
                            }
                        }
                        if (isset($_source[2])) {
                            $prefix = $_source[2];
                        }
                    }
                    break;
                //Загружает данные авторизованного пользователя, user:web:user
                case 'user':
                    if (!empty($_source[1])) {
                        $_source[0] = '\modUsers';
                        $_source[1] = $this->modx->getLoginUserID($_source[1]);
                        if (!$_source[1]) {
                            break;
                        }
                    }
                //Загружает данные из произвольной модели MODxAPI
                default:
                    if (!empty($_source[0])) {
                        $classname = $_source[0];
                        if (!is_null($model = $this->loadModel($classname)) && isset($_source[1])) {
                            /** @var \autoTable $data */
                            if ($data = $model->edit($_source[1])) {
                                $fields = $data->toArray();
                                if (isset($_source[2])) {
                                    $prefix = $_source[2];
                                }
                            }
                        }
                    }
            }
            if (is_array($fields)) {
                if (!is_numeric($keepDefaults)) {
                    $allowed = $submitted ? $this->config->loadArray($keepDefaults) : array();
                    $fields = $this->filterFields($fields, $allowed);
                }
                $this->setFields($fields, $prefix);
                if ($fields) {
                    $this->log('Set external fields from ' . $_source[0], $fields);
                }
            }
        }

        return $this;
    }

    /**
     * Сохранение массива $_REQUEST
     */
    public function setRequestParams()
    {
        $this->setFields($this->_rq);
        if ($emptyFields = $this->emptyFormControls) {
            foreach ($emptyFields as $field => $value) {
                if (!isset($this->_rq[$field])) {
                    $this->setField($field, $value);
                }
            }
        }
        $this->log('Set fields from $_REQUEST', $this->_rq);

        return $this;
    }

    /**
     * Фильтрация полей по спискам разрешенных и запрещенных
     * @param array $fields
     * @param array $allowedFields
     * @param array $forbiddenFields
     * @return array
     */
    public function filterFields($fields = array(), $allowedFields = array(), $forbiddenFields = array())
    {
        $out = array();
        foreach ($fields as $key => $value) {
            //список рарешенных полей существует и поле в него входит; или списка нет, тогда пофиг
            $allowed = !empty($allowedFields) ? in_array($key, $allowedFields) : true;
            //поле входит в список запрещенных полей
            $forbidden = !empty($forbiddenFields) ? in_array($key, $forbiddenFields) : false;
            if (($allowed && !$forbidden) && ($value !== '')) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return bool
     */
    public function isSubmitted()
    {
        $out = $this->formid && ($this->getField('formid') === $this->formid);

        return $out;
    }

    /**
     * Получение информации из конфига
     *
     * @param string $name имя параметра в конфиге
     * @param mixed $def значение по умолчанию, если в конфиге нет искомого параметра
     * @return mixed значение из конфига
     */
    public function getCFGDef($name, $def = null)
    {
        return $this->config->getCFGDef($name, $def);
    }

    /**
     * Сценарий работы
     * Если форма отправлена, то проверяем данные
     * Если проверка успешна, то обрабатываем данные
     * Выводим шаблон
     *
     * @return string
     */
    public function render()
    {
        if ($this->isSubmitted()) {
            $this->validateForm();
            if ($this->isValid()) {
                $this->runPrepare('prepareProcess');
                if ($this->isValid()) {
                    $this->process();
                    $this->log('Form procession complete', $this->getFormData());
                }
            }
        }

        return $this->renderForm();
    }

    /**
     * Готовит данные для вывода в шаблоне
     * @param bool $convertArraysToStrings
     * @return array
     */
    public function prerenderForm($convertArraysToStrings = false)
    {
        $plh = array_merge(
            $this->fieldsToPlaceholders($this->getFormData('fields'), 'value',
                $this->getFormData('status') || $convertArraysToStrings),
            $this->controlsToPlaceholders(),
            $this->errorsToPlaceholders(),
            array('form.messages' => $this->renderMessages())
        );

        return $plh;
    }

    /**
     * Вывод шаблона
     *
     * @return null|string
     */
    public function renderForm()
    {
        $api = $this->getCFGDef('api', 0);
        $plh = $this->getCFGDef('skipPrerender',0) ? $this->getFormData('fields') : $this->prerenderForm();
        $this->log('Render output', array('template' => $this->renderTpl, 'data' => $plh));
        $form = $this->parseChunk($this->renderTpl, $plh);
        /*
         * Если api = 0, то возвращается шаблон
         * Если api = 1, то возвращаются данные формы
         * Если api = 2, то возвращаются данные формы и шаблон
         */
        if (!$api) {
            $out = $form;
        } else {
            $out = $this->getFormData();
            if ($api == 2) {
                $out['output'] = $form;
            }
            $out = json_encode($out);
        }
        $this->log('Output', $out);

        return $out;
    }

    /**
     * Загружает данные в formData
     * @param array $fields массив полей
     * @param string $prefix добавляет префикс к имени поля
     * @return $this
     */
    public function setFields($fields = array(), $prefix = '')
    {
        foreach ($fields as $key => $value) {
            if (is_int($key)) {
                continue;
            }
            if ($prefix) {
                $key = "{$prefix}.{$key}";
            }
            $this->setField($key, $value);
        }

        return $this;
    }

    /**
     * Возвращает результат проверки формы
     * @return bool
     */
    public function validateForm()
    {
        $validator = $this->getCFGDef('validator', '\FormLister\Validator');
        $validator = $this->loadModel($validator, 'assets/snippets/FormLister/lib/Validator.php');
        $fields = $this->getFormData('fields');
        $rules = $this->getValidationRules();
        $this->rules = array_merge($this->rules, $rules);
        $this->log('Prepare to validate fields', array('fields' => $fields, 'rules' => $this->rules));
        $result = $this->validate($validator, $this->rules, $fields);
        if ($result !== true) {
            foreach ($result as $item) {
                $this->addError($item[0], $item[1], $item[2]);
            }
            $this->log('Validation errors', $this->getFormData('errors'));
        }

        return $this->isValid();
    }

    /**
     * Возвращает результаты выполнения правил валидации
     * @param object $validator
     * @param array $rules
     * @param  array $fields
     * @return bool|array
     */
    public function validate($validator, $rules, $fields)
    {
        if (empty($rules) || is_null($validator)) {
            return true;
        } //если правил нет, то не проверяем
        //применяем правила
        $errors = array();
        foreach ($rules as $field => $ruleSet) {
            $skipFlag = substr($field, 0, 1) == '!' ? true : false;
            if ($skipFlag) {
                $field = substr($field, 1);
            }
            $value = \APIHelpers::getkey($fields, $field);
            if ($skipFlag && empty($value)) {
                continue;
            }
            foreach ($ruleSet as $rule => $description) {
                $inverseFlag = substr($rule, 0, 1) == '!' ? true : false;
                if ($inverseFlag) {
                    $rule = substr($rule, 1);
                }
                $result = true;
                if (is_array($description)) {
                    if (isset($description['params'])) {
                        if (is_array($description['params'])) {
                            $params = $description['params'];
                            $params = array_merge(array($value), $params);
                        } else {
                            $params = array($value, $description['params']);
                        }
                    }
                    $message = isset($description['message']) ? $description['message'] : '';
                } else {
                    $params = array($value, $description);
                    $message = $description;
                }
                if (method_exists($validator, $rule)) {
                    $result = call_user_func_array(array($validator, $rule), $params);
                } else {
                    if (isset($description['function'])) {
                        $rule = $description['function'];
                        if (is_callable($rule)) {
                            array_unshift($params, $this);
                            $result = call_user_func_array($rule, $params);
                        }
                    }
                }
                if (is_string($result)) {
                    $message = $result;
                    $result = false;
                }
                if ($inverseFlag) {
                    $result = !$result;
                }
                if (!$result) {
                    $errors[] = array(
                        $field,
                        $rule,
                        $message
                    );
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Возвращает массив formData или его часть
     * @param string $section
     * @return array
     */
    public function getFormData($section = '')
    {
        if ($section && isset($this->formData[$section])) {
            $out = $this->formData[$section];
        } else {
            $out = $this->formData;
        }

        return $out;
    }

    /**
     * Устанавливает статус формы, если true, то форма успешно обработана
     * @param bool $status
     * @return $this
     */
    public function setFormStatus($status)
    {
        $this->formData['status'] = (bool)$status;

        return $this;
    }

    /**
     * Возращвет статус формы
     * @return bool
     */
    public function getFormStatus()
    {
        return $this->formData['status'];
    }

    /**
     * Возвращает значение поля из formData
     * @param $field
     * @return string
     */
    public function getField($field)
    {
        return \APIhelpers::getkey($this->formData['fields'], $field);
    }

    /**
     * Сохраняет значение поля в formData
     * @param string $field имя поля
     * @param $value
     * @return $this
     */
    public function setField($field, $value)
    {
        if ($value !== '' || $this->getCFGDef('allowEmptyFields', 1)) {
            $this->formData['fields'][$field] = $value;
        }

        return $this;
    }

    /**
     * @param string $placeholder
     * @param $value
     * @return $this
     */
    public function setPlaceholder($placeholder, $value)
    {
        $this->placeholders[$placeholder] = $value;

        return $this;
    }

    /**
     * @param $placeholder
     * @return mixed
     */
    public function getPlaceholder($placeholder)
    {
        return \APIhelpers::getkey($this->placeholders, $placeholder);
    }

    /**
     * Удаляет поле из formData
     * @param string $field
     * @return $this
     */
    public function unsetField($field)
    {
        if (isset($this->formData['fields'][$field])) {
            unset($this->formData['fields'][$field]);
        }

        return $this;
    }

    /**
     * Добавляет в formData информацию об ошибке
     * @param string $field имя поля
     * @param string $type тип ошибки
     * @param string $message сообщение об ошибке
     * @return $this
     */
    public function addError($field, $type, $message)
    {
        $this->formData['errors'][$field][$type] = $message;

        return $this;
    }

    /**
     * Добавляет сообщение в formData
     * @param string $message
     * @return $this
     */
    public function addMessage($message = '')
    {
        if ($message) {
            $this->formData['messages'][] = $message;
        }

        return $this;
    }

    /**
     * Готовит данные для вывода в шаблон
     * @param array $fields массив с данными
     * @param string $suffix добавляет суффикс к имени поля
     * @param bool $split преобразование массивов в строки
     * @return array
     */
    public function fieldsToPlaceholders($fields = array(), $suffix = '', $split = false)
    {
        $plh = $fields;
        if (is_array($fields) && !empty($fields)) {
            foreach ($fields as $field => $value) {
                $field = array($field, $suffix);
                $field = implode('.', array_filter($field));
                if ($split && is_array($value)) {
                    $arraySplitter = $this->getCFGDef($field . 'Splitter', $this->getCFGDef('arraySplitter', '; '));
                    $value = implode($arraySplitter, $value);
                }
                $plh[$field] = \APIhelpers::e($value);
            }
        }
        if (!empty($this->placeholders)) {
            $plh = array_merge($plh, $this->placeholders);
        }

        return $plh;
    }

    /**
     * Готовит сообщения об ошибках для вывода в шаблон
     * @return array
     */
    public function errorsToPlaceholders()
    {
        $plh = array();
        foreach ($this->getFormData('errors') as $field => $error) {
            foreach ($error as $type => $message) {
                $classType = ($type == 'required') ? 'required' : 'error';
                $plh[$field . '.error'] = $this->parseChunk($this->getCFGDef('errorTpl',
                    '@CODE:<div class="error">[+message+]</div>'), array('message' => $message));
                $plh[$field . '.' . $classType . 'Class'] = $this->getCFGDef($field . '.' . $classType . 'Class',
                    $this->getCFGDef($classType . 'Class', $classType));
            }
        }

        return $plh;
    }

    /**
     * Обработка чекбоксов, селектов, радио-кнопок перед выводом в шаблон
     * @return array
     */
    public function controlsToPlaceholders()
    {
        $plh = array();
        $formControls = $this->config->loadArray($this->getCFGDef('formControls'));
        foreach ($formControls as $field) {
            $value = $this->getField($field);
            if ($value === '') {
                continue;
            } elseif (is_array($value)) {
                foreach ($value as $_value) {
                    $plh["s.{$field}.{$_value}"] = 'selected';
                    $plh["c.{$field}.{$_value}"] = 'checked';
                }
            } else {
                $plh["s.{$field}.{$value}"] = 'selected';
                $plh["c.{$field}.{$value}"] = 'checked';
            }
        }

        return $plh;
    }

    /**
     * Загрузка правил валидации
     * @param string $param
     * @return array|mixed|\xNop
     */
    public function getValidationRules($param = 'rules')
    {
        $rules = $this->getCFGDef($param);
        $rules = $this->config->loadArray($rules, '');

        return is_array($rules) ? $rules : array();
    }

    /**
     * Готовит сообщения из formData для вывода в шаблон
     * @return string
     */
    public function renderMessages()
    {
        $out = '';
        $formMessages = $this->getFormData('messages');
        $formErrors = $this->getFormData('errors');

        $requiredMessages = $errorMessages = array();
        if ($formErrors) {
            foreach ($formErrors as $field => $error) {
                $type = key($error);
                if ($type == 'required') {
                    $requiredMessages[] = $error[$type];
                } else {
                    $errorMessages[] = $error[$type];
                }
            }
        }
        $wrapper = $this->getCFGDef('messagesTpl', '@CODE:<div class="form-messages">[+messages+]</div>');
        $formMessages = array_filter($formMessages);
        $formErrors = array_filter($formErrors);
        if (!empty($formMessages) || !empty($formErrors)) {
            $out = $this->parseChunk($wrapper,
                array(
                    'messages' => $this->renderMessagesGroup(
                        $formMessages,
                        'messagesOuterTpl',
                        'messagesSplitter'),
                    'required' => $this->renderMessagesGroup(
                        $requiredMessages,
                        'messagesRequiredOuterTpl',
                        'messagesRequiredSplitter'),
                    'errors'   => $this->renderMessagesGroup(
                        $errorMessages,
                        'messagesErrorOuterTpl',
                        'messagesErrorSplitter'),
                ));
        }

        return $out;
    }

    /**
     * @param array $messages
     * @param string $wrapper
     * @param string $splitter
     * @return string
     */
    public function renderMessagesGroup($messages, $wrapper, $splitter)
    {
        $out = '';
        if (is_array($messages) && !empty($messages)) {
            $out = implode($this->getCFGDef($splitter, '<br>'), $messages);
            $wrapperChunk = $this->getCFGDef($wrapper, '@CODE: [+messages+]');
            $out = $this->parseChunk($wrapperChunk, array('messages' => $out));
        }

        return $out;
    }

    /**
     * @param $name
     * @param $data
     * @param bool $parseDocumentSource
     * @return string
     */
    public function parseChunk($name, $data, $parseDocumentSource = false)
    {
        $DLTemplate = \DLTemplate::getInstance($this->modx)
            ->setTemplatePath($this->getCFGDef('templatePath'))
            ->setTemplateExtension($this->getCFGDef('templateExtension'))
            ->setTwigTemplateVars(array(
                'FormLister' => $this,
                'errors' => $this->getFormData('errors'),
                'messages' => $this->getFormData('messages'),
                )
            );
        $out = $DLTemplate->parseChunk($name, $data, $parseDocumentSource);
        if ($this->lexicon->isReady()) {
            $out = $this->lexicon->parseLang($out);
        }

        return $out;
    }

    /**
     * Загружает класс капчи
     */
    public function initCaptcha()
    {
        if ($captcha = $this->getCFGDef('captcha')) {
            $captcha = preg_replace('/[^a-zA-Z]/','',$captcha);
            $wrapper = MODX_BASE_PATH . "assets/snippets/FormLister/lib/captcha/{$captcha}/wrapper.php";
            if ($this->fs->checkFile($wrapper)) {
                include_once($wrapper);
                $wrapper = $captcha . 'Wrapper';
                /** @var \modxCaptchaWrapper $captcha */
                $cfg = $this->config->loadArray($this->getCFGDef('captchaParams',array()));
                $cfg['id'] = $this->getFormId();
                $captcha = new $wrapper ($this->modx, $cfg);
                $captcha->init();
                $this->rules[$this->getCFGDef('captchaField', 'vericode')] = array(
                    "captcha"   => array(
                        "function" => "{$wrapper}::validate",
                        "params" => array($captcha)
                    )
                );
                $this->captcha = $captcha;
                $this->setPlaceholder('captcha', $captcha->getPlaceholder());
            }
        }

        return $this;
    }

    /**
     * @return \DocumentParser|null
     */
    public function getMODX()
    {
        return $this->modx;
    }

    /**
     * @return mixed|string
     */
    public function getFormId()
    {
        return $this->formid;
    }

    /**
     * @return bool
     */
    public function isValid()
    {
        $this->setValid(!count($this->getFormData('errors')));

        return $this->valid;
    }

    /**
     * Вызов prepare-сниппетов
     * @param string $paramName
     * @return $this
     */
    public function runPrepare($paramName = 'prepare')
    {
        if (($prepare = $this->getCFGDef($paramName)) != '') {
            $names = $this->config->loadArray($prepare);
            foreach ($names as $item) {
                $this->callPrepare($item, array(
                    'modx'       => $this->modx,
                    'data'       => $this->getFormData('fields'),
                    'FormLister' => $this
                ));
            }
            $this->log('Prepare finished', $this->getFormData('fields'));
        }

        return $this;
    }

    /**
     * @param $name
     * @param array $params
     * @return $this|void
     */
    public function callPrepare($name, $params = array())
    {
        if (!empty($name)) {
            if ((is_object($name) && ($name instanceof \Closure)) || is_callable($name)) {
                call_user_func_array($name, $params);
            } else {
                $this->modx->runSnippet($name, $params);
            }
        }

        return $this;
    }

    /**
     * @param string $param имя параметра с id документа для редиректа
     * В api-режиме редирект не выполняется, но ссылка доступна в formData
     */
    public function redirect($param = 'redirectTo')
    {
        if ($redirect = $this->getCFGDef($param, 0)) {
            $redirect = $this->config->loadArray($redirect);
            $query = $header = '';
            if (is_array($redirect)) {
                if (isset($redirect['query'])) $query = http_build_query($query);
                if (isset($redirect['header'])) $header = $redirect['header'];
                $page = isset($redirect['page']) ? $redirect['page'] : 0;
            } else {
                $page = $redirect;
            }

            $redirect = $this->modx->makeUrl($page, '', $query, 'full');
            $this->setField($param, $redirect);
            $this->log('Redirect (' . $param . ') to' . $redirect, array('data' => $this->getFormData('fields')));
            if (!$this->getCFGDef('api', 0)) {
                $header = $header ? $header : 'HTTP/1.1 307 Temporary Redirect';
                $this->modx->sendRedirect($redirect, 0, 'REDIRECT_HEADER', $header);
            }
        }
    }

    /**
     * Обработка формы, определяется контроллерами
     *
     * @return mixed
     */
    abstract public function process();

    /**
     * @param boolean $valid
     * @return Core
     */
    public function setValid($valid)
    {
        $this->valid &= $valid;

        return $this;
    }

    /**
     * @param array $files
     * @return Core
     */
    public function setFiles($files)
    {
        if (is_array($files)) {
            $this->formData['files'] = $files;
        }

        return $this;
    }

    /**
     * @param string $message
     * @param array $data
     * @return Core
     */
    public function log($message, $data = array())
    {
        if (!is_null($this->debug)) {
            $this->debug->log($message, $data);
        }

        return $this;
    }

    /**
     * @param $model
     * @param string $path
     * @return object
     */
    public function loadModel($model, $path = '')
    {
        $out = null;
        if (class_exists($model)) {
            $out = new $model($this->modx);
        } else {
            if ($path && $this->fs->checkFile($path)) {
                include_once($path);
                $out = new $model($this->modx);
            }
        }

        return $out;
    }

    /**
     * @param array $_files
     * @param array $allowed
     * @param bool $flag
     * @return array
     */
    public function filesToArray(array $_files, array $allowed, $flag = true)
    {
        $files = array();
        foreach ($_files as $name => $file) {
            if (!in_array($name, $allowed) && !is_int($name)) {
                continue;
            }
            if ($flag) {
                $sub_name = $file['name'];
            } else {
                $sub_name = $name;
            }
            if (is_array($sub_name)) {
                foreach (array_keys($sub_name) as $key) {
                    $files[$name][$key] = array(
                        'name'     => $file['name'][$key],
                        'type'     => $file['type'][$key],
                        'tmp_name' => $file['tmp_name'][$key],
                        'error'    => $file['error'][$key],
                        'size'     => $file['size'][$key],
                    );
                    $files[$name] = $this->filesToArray($files[$name], $allowed, false);
                }
            } else {
                $files[$name] = $file;
            }
        }

        return $files;
    }

    /**
     * Возвращает сообщения об ошибках для указанного поля
     * @param $field
     * @return array|bool
     */
    public function getErrorMessage($field) {
        $out = array();
        if (!empty($field && isset($this->formData['errors'][$field]) && is_array($this->formData['errors'][$field]))) {
            $out = array_values($this->formData['errors'][$field]);
        }

        return $out;
    }
}