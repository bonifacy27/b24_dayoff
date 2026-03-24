<?php
/**
 * /forms/dayoff/list.php
 * Версия: v1.0.0 (2026-03-24)
 * - Список заявок на отгул
 * - Поиск по ФИО сотрудника
 * - Отображение ФИО сотрудника вместо ID
 * - В статусе показывается статус заявки + история заявки
 * - Над списком справа добавлен блок текущего баланса часов/дней отгула
 * - Баланс берется из поля пользователя UF_DAYOFF_BALANCE
 * - Количество дней рассчитывается как floor(часы / 8)
 */

use Bitrix\Main\Loader;

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Заявки на отгул");

if (!Loader::includeModule("iblock")) {
    ShowError("Не удалось подключить модуль iblock");
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
    exit;
}

global $USER;

if (!$USER->IsAuthorized()) {
    ShowError("Требуется авторизация");
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
    exit;
}

/**
 * НАСТРОЙКИ
 * Укажите правильный ID инфоблока заявок на отгул и ID инфоблока статусов.
 */
$IBLOCK_ID      = 398; // инфоблок заявок на отгул
$GROUP_ID       = 87;  // группа из URL
$IBLOCK_STATUS  = 399; // !!! УКАЖИТЕ ID справочника статусов, если статусы лежат в отдельном инфоблоке

/**
 * Карта свойств заявок на отгул
 */
$PROP_MAP = [
    3119 => ['code' => 'SOTRUDNIK',   'title' => 'Сотрудник'],
    3117 => ['code' => 'DATE_DAYOFF', 'title' => 'Дата отгула'],
    3121 => ['code' => 'COMMENTS',    'title' => 'Комментарий'],
    3122 => ['code' => 'ISTORIYA',    'title' => 'История'],
    3123 => ['code' => 'STATUS',      'title' => 'Статус'],
];

/* ------------------------- helpers ------------------------- */

function h($s)
{
    return htmlspecialcharsbx((string)$s);
}

function qs(array $params, array $keep = [])
{
    $result = [];
    foreach ($_GET as $k => $v) {
        if (in_array($k, $keep, true)) {
            $result[$k] = $v;
        }
    }
    foreach ($params as $k => $v) {
        $result[$k] = $v;
    }
    return http_build_query($result);
}

function userNameById($userId)
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return '';
    }

    $rsUser = CUser::GetByID($userId);
    if ($arUser = $rsUser->Fetch()) {
        $name = trim($arUser["LAST_NAME"] . " " . $arUser["NAME"] . " " . $arUser["SECOND_NAME"]);
        return $name ?: $arUser["LOGIN"];
    }

    return '';
}

function userBalanceById($userId)
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return 0;
    }

    $rsUser = CUser::GetByID($userId);
    if ($arUser = $rsUser->Fetch()) {
        return (float)$arUser["UF_DAYOFF_BALANCE"];
    }

    return 0;
}

function statusInfoById($statusId, $iblockStatus)
{
    static $cache = [];

    $statusId = (int)$statusId;
    if ($statusId <= 0) {
        return ['NAME' => '', 'COLOR' => '#6c757d'];
    }

    if (isset($cache[$statusId])) {
        return $cache[$statusId];
    }

    if ((int)$iblockStatus <= 0) {
        return $cache[$statusId] = ['NAME' => 'Статус не настроен', 'COLOR' => '#6c757d'];
    }

    $res = CIBlockElement::GetList(
        [],
        [
            "IBLOCK_ID" => $iblockStatus,
            "ID"        => $statusId,
            "ACTIVE"    => "Y"
        ],
        false,
        false,
        ["ID", "NAME", "PROPERTY_COLOR"]
    );

    if ($ar = $res->Fetch()) {
        $color = $ar["PROPERTY_COLOR_VALUE"] ?: "#17a2b8";
        return $cache[$statusId] = [
            "NAME"  => $ar["NAME"],
            "COLOR" => $color,
        ];
    }

    return $cache[$statusId] = ['NAME' => '', 'COLOR' => '#6c757d'];
}

function propValueSafe(array $props, int $iblockId, int $elementId, int $propId, string $propCode)
{
    if (isset($props[$propCode])) {
        $v = $props[$propCode]["VALUE"];
        return is_array($v) ? implode(", ", $v) : $v;
    }

    if (isset($props[$propId])) {
        $v = $props[$propId]["VALUE"];
        return is_array($v) ? implode(", ", $v) : $v;
    }

    $res = CIBlockElement::GetProperty($iblockId, $elementId, [], ["ID" => $propId]);
    $vals = [];
    while ($ar = $res->Fetch()) {
        if ($ar["VALUE"] !== null && $ar["VALUE"] !== "") {
            $vals[] = $ar["VALUE"];
        }
    }

    if (!$vals) {
        return '';
    }

    return count($vals) > 1 ? implode(", ", $vals) : $vals[0];
}

function formatHistoryHtml($historyRaw)
{
    $historyRaw = trim((string)$historyRaw);
    if ($historyRaw === '') {
        return '';
    }

    $lines = preg_split("/\r\n|\n|\r/u", $historyRaw);
    $items = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}:\d{2})\s+(.*)$/u', $line, $m)) {
            $items[] = [
                'datetime' => $m[1],
                'text'     => $m[2],
            ];
        } else {
            $items[] = [
                'datetime' => '',
                'text'     => $line,
            ];
        }
    }

    if (!$items) {
        return '';
    }

    $html = '<ul class="list-unstyled history-list mb-0">';
    foreach ($items as $it) {
        $dt   = $it['datetime'] ? '<div class="history-item-datetime">'.h($it['datetime']).'</div>' : '';
        $text = '<div class="history-item-text">'.h($it['text']).'</div>';
        $html .= '<li class="history-item mb-2">'.$dt.$text.'</li>';
    }
    $html .= '</ul>';

    return $html;
}

/* ------------------------- текущий пользователь ------------------------- */

$currentUserId = (int)$USER->GetID();
$currentBalanceHours = userBalanceById($currentUserId);
$currentBalanceHoursInt = (int)floor($currentBalanceHours);
$currentBalanceDays = (int)floor($currentBalanceHours / 8);

/* ------------------------- сортировка ------------------------- */

$allowedSort = [
    'id'       => 'ID',
    'employee' => 'PROPERTY_3119',
    'date'     => 'PROPERTY_3117',
    'status'   => 'PROPERTY_3123',
];

$sortKey = isset($_GET['sort'], $allowedSort[$_GET['sort']]) ? $_GET['sort'] : 'id';
$dir     = (isset($_GET['dir']) && strtoupper($_GET['dir']) === 'ASC') ? 'ASC' : 'DESC';

$order = [
    $allowedSort[$sortKey] => $dir,
    'ID' => 'DESC'
];

/* ------------------------- фильтр ------------------------- */

$filter = [
    "IBLOCK_ID"         => $IBLOCK_ID,
    "ACTIVE"            => "Y",
    "CHECK_PERMISSIONS" => "Y",
];

$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $matchedUserIds = [];
    $rsUsers = CUser::GetList(
        ($by = "last_name"),
        ($orderUser = "asc"),
        [
            "ACTIVE" => "Y",
            [
                "LOGIC" => "OR",
                "%NAME"        => $q,
                "%LAST_NAME"   => $q,
                "%SECOND_NAME" => $q,
                "%LOGIN"       => $q,
                "%EMAIL"       => $q,
            ]
        ],
        ["FIELDS" => ["ID", "NAME", "LAST_NAME", "SECOND_NAME", "LOGIN", "EMAIL"]]
    );

    while ($arUser = $rsUsers->Fetch()) {
        $matchedUserIds[] = (int)$arUser["ID"];
    }

    if (!empty($matchedUserIds)) {
        $filter["PROPERTY_3119"] = $matchedUserIds;
    } else {
        $filter["ID"] = 0;
    }
}

/* ------------------------- выборка ------------------------- */

$arSelect = ["ID", "NAME"];

$rsItems = CIBlockElement::GetList(
    $order,
    $filter,
    false,
    ["nPageSize" => 50],
    $arSelect
);
?>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">

<style>
  .page-wrap { padding: 16px 24px; }
  .top-panel {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 20px;
    flex-wrap: wrap;
  }
  .search-box {
    flex: 1 1 520px;
  }
  .balance-box {
    flex: 0 0 360px;
    background: linear-gradient(135deg, #17a2b8 0%, #0d6efd 100%);
    color: #fff;
    border-radius: 12px;
    padding: 18px 20px;
    box-shadow: 0 8px 24px rgba(0,0,0,.15);
  }
  .balance-box-title {
    font-size: 15px;
    font-weight: 700;
    margin-bottom: 8px;
    opacity: .95;
  }
  .balance-box-hours {
    font-size: 30px;
    font-weight: 800;
    line-height: 1.1;
    margin-bottom: 8px;
  }
  .balance-box-days {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 8px;
  }
  .balance-box-note {
    font-size: 13px;
    opacity: .9;
    line-height: 1.4;
  }

  .table thead th { white-space: nowrap; }
  .sort-link { color: #fff; text-decoration: none; }
  .sort-link:hover { text-decoration: underline; }
  .sort-caret { font-weight: 700; margin-left: 4px; }

  .status-cell {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }

  .status-history-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    border: 1px solid rgba(0,0,0,.2);
    font-size: 11px;
    background: #fff;
    color: #333;
    cursor: pointer;
  }
  .status-history-icon:hover {
    background: #f1f1f1;
  }

  .history-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
  }
  .history-modal {
    background: #fff;
    border-radius: 8px;
    max-width: 820px;
    width: 90%;
    max-height: 80vh;
    box-shadow: 0 10px 30px rgba(0,0,0,.25);
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .history-modal-header {
    padding: 12px 16px;
    border-bottom: 1px solid #e5e5e5;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .history-modal-title {
    font-size: 16px;
    font-weight: 600;
  }
  .history-modal-close {
    border: 0;
    background: transparent;
    font-size: 24px;
    line-height: 1;
    cursor: pointer;
  }
  .history-modal-body {
    padding: 12px 16px 16px;
    overflow-y: auto;
  }
  body.history-modal-open {
    overflow: hidden;
  }
  .history-item-datetime {
    font-size: 12px;
    color: #6c757d;
    margin-bottom: 2px;
  }
  .history-item-text {
    font-size: 14px;
    color: #212529;
  }
</style>

<div class="container-fluid page-wrap">
  <h2 class="mb-2">Заявки на отгул</h2>
  <p class="mb-3">Список заявок на отгул с поиском по сотруднику и просмотром истории статусов.</p>

  <div class="top-panel">
    <div class="search-box">
      <form method="get" class="form-inline">
        <input type="hidden" name="sort" value="<?= h($sortKey) ?>">
        <input type="hidden" name="dir" value="<?= h(strtolower($dir)) ?>">

        <input
          type="text"
          name="q"
          value="<?= h($q) ?>"
          class="form-control mr-2 mb-2"
          style="min-width: 320px;"
          placeholder="Поиск по ФИО сотрудника"
        >

        <button type="submit" class="btn btn-primary mr-2 mb-2">Найти</button>
        <a href="<?= h($APPLICATION->GetCurPage()) ?>" class="btn btn-secondary mb-2">Сброс</a>
      </form>
    </div>

    <div class="balance-box">
      <div class="balance-box-title">Текущий баланс отгула</div>
      <div class="balance-box-hours"><?= $currentBalanceHoursInt ?> ч.</div>
      <div class="balance-box-days">Доступно дней: <?= $currentBalanceDays ?></div>
      <div class="balance-box-note">
        Отгулы используются по 8 часов.<br>
        Количество доступных дней рассчитывается как целая часть от деления часов на 8.
      </div>
    </div>
  </div>

<?php if ($rsItems->SelectedRowsCount() <= 0): ?>
  <div class="alert alert-info">Нет доступных заявок на отгул.</div>
<?php else: ?>

<?php
$dirOpposite = ($dir === 'ASC') ? 'DESC' : 'ASC';

$makeSortLink = function(string $key, string $title) use ($sortKey, $dir, $dirOpposite) {
    $isActive = ($sortKey === $key);
    $url = '?' . qs(
        [
            'sort' => $key,
            'dir'  => $isActive ? $dirOpposite : 'ASC'
        ],
        ['q']
    );

    $caret = '';
    if ($isActive) {
        $caret = $dir === 'ASC' ? '▲' : '▼';
    }

    return '<a href="'.h($url).'" class="sort-link">'.h($title).($caret ? '<span class="sort-caret">'.$caret.'</span>' : '').'</a>';
};
?>

<div class="table-responsive">
  <table class="table table-sm table-bordered table-hover">
    <thead class="thead-dark">
      <tr>
        <th><?= $makeSortLink('id', 'ID') ?></th>
        <th><?= $makeSortLink('employee', 'Сотрудник') ?></th>
        <th><?= $makeSortLink('date', 'Дата отгула') ?></th>
        <th>Комментарий</th>
        <th><?= $makeSortLink('status', 'Статус') ?></th>
        <th>Открыть</th>
      </tr>
    </thead>
    <tbody>

<?php while ($ob = $rsItems->GetNextElement()):
    $f = $ob->GetFields();
    $p = $ob->GetProperties();

    $employeeId = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3119, $PROP_MAP[3119]['code']);
    $dateDayoff = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3117, $PROP_MAP[3117]['code']);
    $comments   = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3121, $PROP_MAP[3121]['code']);
    $history    = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3122, $PROP_MAP[3122]['code']);
    $statusId   = propValueSafe($p, $IBLOCK_ID, (int)$f['ID'], 3123, $PROP_MAP[3123]['code']);

    $employeeName = $employeeId ? userNameById($employeeId) : '';
    $statusInfo   = $statusId ? statusInfoById($statusId, $IBLOCK_STATUS) : ['NAME' => '', 'COLOR' => '#6c757d'];
    $historyHtml  = formatHistoryHtml($history);

    $openUrl = "/workgroups/group/{$GROUP_ID}/lists/{$IBLOCK_ID}/element/0/{$f['ID']}/?list_section_id=";
?>
      <tr>
        <td><?= (int)$f['ID'] ?></td>
        <td><?= h($employeeName) ?></td>
        <td><?= h($dateDayoff) ?></td>
        <td><?= h($comments) ?></td>
        <td>
          <div class="status-cell">
            <?php if ($statusInfo['NAME']): ?>
              <span class="badge" style="background:<?= h($statusInfo['COLOR']) ?>; color:#fff;">
                <?= h($statusInfo['NAME']) ?>
              </span>
            <?php endif; ?>

            <?php if ($historyHtml): ?>
              <button
                type="button"
                class="status-history-icon js-history-icon"
                data-history-id="history-<?= (int)$f['ID'] ?>"
                title="Показать историю заявки"
              >i</button>

              <div id="history-<?= (int)$f['ID'] ?>" class="d-none">
                <?= $historyHtml ?>
              </div>
            <?php endif; ?>
          </div>
        </td>
        <td>
          <a href="<?= h($openUrl) ?>" target="_blank" rel="noopener">Открыть</a>
        </td>
      </tr>
<?php endwhile; ?>

    </tbody>
  </table>
</div>

<?php endif; ?>
</div>

<div id="history-modal-backdrop" class="history-modal-backdrop">
  <div class="history-modal">
    <div class="history-modal-header">
      <div class="history-modal-title">История заявки</div>
      <button type="button" class="history-modal-close js-history-close">&times;</button>
    </div>
    <div class="history-modal-body" id="history-modal-body"></div>
  </div>
</div>

<script>
(function() {
  var backdrop = document.getElementById('history-modal-backdrop');
  if (!backdrop) return;

  var bodyEl = document.getElementById('history-modal-body');

  function openHistory(html) {
    bodyEl.innerHTML = html;
    backdrop.style.display = 'flex';
    document.body.classList.add('history-modal-open');
  }

  function closeHistory() {
    backdrop.style.display = 'none';
    document.body.classList.remove('history-modal-open');
    bodyEl.innerHTML = '';
  }

  backdrop.addEventListener('click', function(e) {
    if (e.target === backdrop || e.target.closest('.js-history-close')) {
      closeHistory();
    }
  });

  document.addEventListener('click', function(e) {
    var icon = e.target.closest ? e.target.closest('.js-history-icon') : null;
    if (!icon) return;

    var id = icon.getAttribute('data-history-id');
    var container = document.getElementById(id);
    if (container) {
      openHistory(container.innerHTML);
    }
  });
})();
</script>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>