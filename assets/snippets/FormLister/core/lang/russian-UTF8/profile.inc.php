<?php
/**
 * Created by PhpStorm.
 * User: Pathologic
 * Date: 15.05.2016
 * Time: 1:26
 */
if (!defined('MODX_BASE_PATH')) {die();}

setlocale(LC_ALL, 'ru_RU.UTF-8');

$_lang = array();
$_lang['profile.default_skipTpl'] = '@CODE:Для изменения профиля вы должны быть авторизованы.';
$_lang['profile.update_fail'] = 'Не удалось сохранить данные.';
$_lang['profile.update_success'] = 'Данные успешно сохранены.';
return $_lang;