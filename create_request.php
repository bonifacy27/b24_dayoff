<?php
/**
 * /forms/hr_administration/dayoff/create_request.php
 * Создание заявки на отгул.
 * Версия: v2.0.0 (2026-03-24)
 */

use Bitrix\Main\Loader;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';

$APPLICATION->SetTitle('Создание заявки на отгул');

if (!Loader::includeModule('iblock') || !Loader::includeModule('bizproc')) {
    ShowError('Не удалось подключить модули iblock/bizproc');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    return;
}

global $USER;

if (!$USER->IsAuthorized()) {
    ShowError('Требуется авторизация');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    return;
}

$IBLOCK_DAYOFF_REQUESTS = 398;
$BP_TEMPLATE_ID = 1311;

$STATUS_COMPLETED_ID = 3511824;
$STATUS_CANCELLED_ID = 3511775;

$PROP_EMPLOYEE = 'SOTRUDNIK';
$PROP_DAYOFF_DATE = 'DATE_DAYOFF';
$PROP_COMMENTS = 'COMMENTS';
$PROP_STATUS = 'STATUS';

function h($value): string
{
    return htmlspecialcharsbx((string)$value);
}

function dayoffGetUserData(int $userId): array
{
    $result = [
        'id' => $userId,
        'full_name' => '',
        'position' => '',
        'balance_hours' => 0.0,
    ];

    if ($userId <= 0) {
        return $result;
    }

    $rsUser = CUser::GetByID($userId);
    if ($arUser = $rsUser->Fetch()) {
        $result['full_name'] = trim(($arUser['LAST_NAME'] ?? '') . ' ' . ($arUser['NAME'] ?? '') . ' ' . ($arUser['SECOND_NAME'] ?? ''));
        $result['position'] = trim((string)($arUser['WORK_POSITION'] ?? ''));
        $result['balance_hours'] = (float)str_replace(',', '.', (string)($arUser['UF_DAYOFF_BALANCE'] ?? 0));
    }

    return $result;
}

function dayoffGetPendingHours(int $userId, int $iblockId, string $employeeProp, string $statusProp, array $excludedStatuses): float
{
    if ($userId <= 0 || $iblockId <= 0) {
        return 0.0;
    }

    $filter = [
        'IBLOCK_ID' => $iblockId,
        'ACTIVE' => 'Y',
        'PROPERTY_' . $employeeProp => $userId,
    ];

    if (!empty($excludedStatuses)) {
        $filter['!PROPERTY_' . $statusProp] = $excludedStatuses;
    }

    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        $filter,
        false,
        false,
        ['ID']
    );

    $count = 0;
    while ($res->Fetch()) {
        $count++;
    }

    return (float)($count * 8);
}

$currentUserId = (int)$USER->GetID();
$currentUser = dayoffGetUserData($currentUserId);

$balanceHoursRaw = max(0.0, (float)$currentUser['balance_hours']);
$pendingHours = dayoffGetPendingHours(
    $currentUserId,
    $IBLOCK_DAYOFF_REQUESTS,
    $PROP_EMPLOYEE,
    $PROP_STATUS,
    [$STATUS_CANCELLED_ID, $STATUS_COMPLETED_ID]
);

$availableHours = max(0.0, $balanceHoursRaw - $pendingHours);
$availableDays = (int)floor($availableHours / 8);

$errors = [];
$form = [
    'date_dayoff' => '',
    'comments' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'create') {
    $form['date_dayoff'] = trim((string)($_POST['date_dayoff'] ?? ''));
    $form['comments'] = trim((string)($_POST['comments'] ?? ''));

    if (!check_bitrix_sessid()) {
        $errors[] = 'Сессия истекла. Обновите страницу.';
    }

    if ($availableDays < 1) {
        $errors[] = 'Недостаточно доступных часов для оформления отгула. Доступно дней: 0.';
    }

    if ($form['date_dayoff'] === '') {
        $errors[] = 'Укажите дату отгула.';
    }

    if (!$errors) {
        $el = new CIBlockElement();

        $fields = [
            'IBLOCK_ID' => $IBLOCK_DAYOFF_REQUESTS,
            'IBLOCK_SECTION_ID' => false,
            'NAME' => 'Заявка на отгул от ' . date('d.m.Y H:i:s'),
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => [
                $PROP_EMPLOYEE => $currentUserId,
                $PROP_DAYOFF_DATE => $form['date_dayoff'],
                $PROP_COMMENTS => $form['comments'],
            ],
        ];

        $elementId = (int)$el->Add($fields);

        if ($elementId <= 0) {
            $errors[] = 'Не удалось создать заявку: ' . $el->LAST_ERROR;
        } else {
            $documentId = ['lists', 'Bitrix\\Lists\\BizprocDocumentLists', $elementId];
            $startErrors = [];
            $wfId = CBPDocument::StartWorkflow(
                $BP_TEMPLATE_ID,
                $documentId,
                [],
                $startErrors
            );

            if (!$wfId) {
                $errors[] = 'Заявка создана, но не удалось запустить бизнес-процесс: ' . implode('; ', array_unique(array_filter($startErrors)));
            }
        }

        if (!$errors) {
            LocalRedirect('/forms/hr_administration/dayoff/list.php');
        }
    }
}
?>

<style>
.dayoff-wrapper { max-width: 860px; }
.dayoff-balance { margin: 0 0 18px; padding: 14px 16px; border: 1px solid #dfe3e8; border-radius: 8px; background: #f8fbff; }
.dayoff-balance__item { margin: 2px 0; }
.dayoff-form .ui-ctl { max-width: 420px; width: 100%; }
.dayoff-user-box { padding: 10px 12px; border: 1px solid #dfe3e8; border-radius: 6px; background: #fff; max-width: 420px; }
</style>

<div class="dayoff-wrapper">
    <div class="dayoff-balance">
        <div class="dayoff-balance__item"><strong>Доступно часов отгула:</strong> <?= h($availableHours) ?></div>
        <div class="dayoff-balance__item"><strong>Уже в заявках (на согласовании):</strong> <?= h($pendingHours) ?></div>
        <div class="dayoff-balance__item"><strong>Доступно дней отгула (по 8 часов):</strong> <?= h($availableDays) ?></div>
    </div>

    <?php if ($errors): ?>
        <div class="ui-alert ui-alert-danger">
            <?php foreach ($errors as $error): ?>
                <div><?= h($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="dayoff-form">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="create">

        <div class="ui-form-row">
            <div class="ui-form-label"><span class="ui-ctl-label-text">Сотрудник</span></div>
            <div class="ui-form-content">
                <div class="dayoff-user-box">
                    <?= h($currentUser['full_name']) ?><?= $currentUser['position'] !== '' ? ' — ' . h($currentUser['position']) : '' ?>
                </div>
            </div>
        </div>

        <div class="ui-form-row">
            <div class="ui-form-label"><span class="ui-ctl-label-text">Дата отгула</span></div>
            <div class="ui-form-content">
                <div class="ui-ctl ui-ctl-textbox">
                    <input type="date" class="ui-ctl-element" name="date_dayoff" value="<?= h($form['date_dayoff']) ?>" required>
                </div>
            </div>
        </div>

        <div class="ui-form-row">
            <div class="ui-form-label"><span class="ui-ctl-label-text">Комментарий</span></div>
            <div class="ui-form-content">
                <div class="ui-ctl ui-ctl-textarea">
                    <textarea class="ui-ctl-element" name="comments" rows="4" placeholder="Необязательно"><?= h($form['comments']) ?></textarea>
                </div>
            </div>
        </div>

        <div class="ui-form-row">
            <div class="ui-form-content">
                <button type="submit" class="ui-btn ui-btn-success">Создать заявку</button>
                <a href="/forms/hr_administration/dayoff/list.php" class="ui-btn ui-btn-light-border">Назад к списку</a>
            </div>
        </div>
    </form>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
