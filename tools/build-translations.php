<?php

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$translations = array(
	'ru_RU' => array(
		'Totobi Integration' => 'Интеграция Totobi',
		'Import Totobi products, prices, stock, variations, and images into WooCommerce.' => 'Импорт товаров, цен, остатков, вариаций и изображений Totobi в WooCommerce.',
		'Settings' => 'Настройки',
		'Feed source, categories, schedule, and product update rules.' => 'Источник фида, категории, расписание и правила обновления товаров.',
		'Prom YML feed URL' => 'URL Prom YML фида',
		'Main YML fallback URL' => 'Резервный URL основного YML',
		'Category mode' => 'Режим категорий',
		'Automatic from client category list' => 'Автоматически по списку категорий клиента',
		'Manual mapping' => 'Ручное сопоставление',
		'Totobi categories' => 'Категории Totobi',
		'Automatic mode uses the approved client category list. Switch to manual mapping to edit this list.' => 'Автоматический режим использует утвержденный список категорий клиента. Переключитесь на ручное сопоставление, чтобы редактировать этот список.',
		'WooCommerce category mapping' => 'Сопоставление категорий WooCommerce',
		'Automatic mode keeps WooCommerce mapping locked. Switch to manual mapping to edit assignments.' => 'В автоматическом режиме сопоставление WooCommerce заблокировано. Переключитесь на ручное сопоставление, чтобы редактировать назначения.',
		'Markup percent' => 'Процент наценки',
		'First sync time' => 'Время первой синхронизации',
		'Next automatic run: %s' => 'Следующий автоматический запуск: %s',
		'Automatic sync is not scheduled yet.' => 'Автоматическая синхронизация еще не запланирована.',
		'Sync interval' => 'Интервал синхронизации',
		'Every 4 hours' => 'Каждые 4 часа',
		'Every 6 hours' => 'Каждые 6 часов',
		'Daily' => 'Ежедневно',
		'Totobi Prom YML is checked on this interval. If the catalog date has not changed, the automatic sync is skipped.' => 'Prom YML Totobi проверяется с этим интервалом. Если дата каталога не изменилась, автоматическая синхронизация пропускается.',
		'Product update mode' => 'Режим обновления товаров',
		'Products are published automatically. Images are imported from Totobi and unchanged products are skipped.' => 'Товары публикуются автоматически. Изображения импортируются из Totobi, а неизмененные товары пропускаются.',
		'Save settings' => 'Сохранить настройки',
		'Manual sync' => 'Ручная синхронизация',
		'Starts the Totobi update now. Progress is shown live while product data and images are processed in small batches.' => 'Запускает обновление Totobi сейчас. Прогресс отображается вживую, пока данные товаров и изображения обрабатываются небольшими пакетами.',
		'Start sync' => 'Запустить синхронизацию',
		'Pause' => 'Пауза',
		'Resume' => 'Продолжить',
		'Reset sync' => 'Сбросить синхронизацию',
		'Waiting to start' => 'Ожидание запуска',
		'processed' => 'обработано',
		'simple updated' => 'простых обновлено',
		'variable updated' => 'вариативных обновлено',
		'variations updated' => 'вариаций обновлено',
		'images downloaded' => 'изображений загружено',
		'unchanged skipped' => 'неизмененных пропущено',
		'products unchanged skipped' => 'товаров без изменений пропущено',
		'missing set out of stock' => 'отсутствующих нет в наличии',
		'errors' => 'ошибок',
		'Last sync' => 'Последняя синхронизация',
		'Short result from the most recent import run.' => 'Краткий результат последнего запуска импорта.',
		'Recent log' => 'Последние события',
		'Status' => 'Статус',
		'Finished' => 'Завершено',
		'Catalog date' => 'Дата каталога',
		'Created' => 'Создано',
		'Updated' => 'Обновлено',
		'Unchanged' => 'Без изменений',
		'Out of stock' => 'Нет в наличии',
		'Images' => 'Изображения',
		'Download updated products CSV' => 'Скачать CSV обновленных товаров',
		'Errors' => 'Ошибки',
		'No log entries yet.' => 'Записей журнала пока нет.',
		'Log' => 'Журнал',
		'Sync event recorded.' => 'Событие синхронизации записано.',
		'Feed check started.' => 'Проверка фида началась.',
		'Feed check completed.' => 'Проверка фида завершена.',
		'Could not download Totobi feed.' => 'Не удалось загрузить фид Totobi.',
		'Could not read Totobi feed.' => 'Не удалось прочитать фид Totobi.',
		'Could not read product list.' => 'Не удалось прочитать список товаров.',
		'Automatic sync skipped because Totobi data did not change.' => 'Автоматическая синхронизация пропущена: данные Totobi не изменились.',
		'Completed' => 'Завершено',
		'Skipped' => 'Пропущено',
		'Error' => 'Ошибка',
		'No sync yet' => 'Синхронизации еще не было',
		'Settings saved.' => 'Настройки сохранены.',
		'Manual sync check completed.' => 'Проверка ручной синхронизации завершена.',
		'Manual sync check failed. See log for details.' => 'Проверка ручной синхронизации не удалась. Подробности в журнале.',
		'Cannot load WooCommerce categories.' => 'Не удалось загрузить категории WooCommerce.',
		'Totobi ID' => 'ID Totobi',
		'Totobi category' => 'Категория Totobi',
		'Client path' => 'Путь клиента',
		'WooCommerce category' => 'Категория WooCommerce',
		'Do not assign' => 'Не назначать',
		'Insufficient permissions.' => 'Недостаточно прав.',
		'Preparing Totobi feed...' => 'Подготовка фида Totobi...',
		'Cannot start sync' => 'Не удалось запустить синхронизацию',
		'Sync started. Processing first batch...' => 'Синхронизация запущена. Обрабатывается первый пакет...',
		'Sync started. Products: %total%, catalog: %catalog%' => 'Синхронизация запущена. Товаров: %total%, каталог: %catalog%',
		'AJAX start request failed' => 'AJAX-запрос запуска не удался',
		'Processing next batch...' => 'Обработка следующего пакета...',
		'Batch failed' => 'Пакет не обработан',
		'ERROR:' => 'ОШИБКА:',
		'Sync completed.' => 'Синхронизация завершена.',
		'Batch request failed. Retry %count% in 5 seconds...' => 'Запрос пакета не удался. Повтор %count% через 5 секунд...',
		'Batch request failed. Retrying...' => 'Запрос пакета не удался. Повтор...',
		'Sync paused.' => 'Синхронизация приостановлена.',
		'Sync resumed.' => 'Синхронизация продолжена.',
		'Sync resumed. Processing next batch...' => 'Синхронизация продолжена. Обрабатывается следующий пакет...',
		'simple products' => 'простые товары',
		'variable products' => 'вариативные товары',
		'missing products' => 'отсутствующие товары',
		'Processing %stage%: %processed% of %total%' => 'Обработка: %stage%, %processed% из %total%',
		'Reset the current sync session?' => 'Сбросить текущую сессию синхронизации?',
		'Sync session reset.' => 'Сессия синхронизации сброшена.',
		'Sync was interrupted. Continue?' => 'Синхронизация была прервана. Продолжить?',
	),
	'uk' => array(
		'Totobi Integration' => 'Інтеграція Totobi',
		'Import Totobi products, prices, stock, variations, and images into WooCommerce.' => 'Імпорт товарів, цін, залишків, варіацій та зображень Totobi у WooCommerce.',
		'Settings' => 'Налаштування',
		'Feed source, categories, schedule, and product update rules.' => 'Джерело фіда, категорії, розклад і правила оновлення товарів.',
		'Prom YML feed URL' => 'URL Prom YML фіда',
		'Main YML fallback URL' => 'Резервний URL основного YML',
		'Category mode' => 'Режим категорій',
		'Automatic from client category list' => 'Автоматично за списком категорій клієнта',
		'Manual mapping' => 'Ручне зіставлення',
		'Totobi categories' => 'Категорії Totobi',
		'Automatic mode uses the approved client category list. Switch to manual mapping to edit this list.' => 'Автоматичний режим використовує затверджений список категорій клієнта. Перемкніться на ручне зіставлення, щоб редагувати цей список.',
		'WooCommerce category mapping' => 'Зіставлення категорій WooCommerce',
		'Automatic mode keeps WooCommerce mapping locked. Switch to manual mapping to edit assignments.' => 'В автоматичному режимі зіставлення WooCommerce заблоковано. Перемкніться на ручне зіставлення, щоб редагувати призначення.',
		'Markup percent' => 'Відсоток націнки',
		'First sync time' => 'Час першої синхронізації',
		'Next automatic run: %s' => 'Наступний автоматичний запуск: %s',
		'Automatic sync is not scheduled yet.' => 'Автоматичну синхронізацію ще не заплановано.',
		'Sync interval' => 'Інтервал синхронізації',
		'Every 4 hours' => 'Кожні 4 години',
		'Every 6 hours' => 'Кожні 6 годин',
		'Daily' => 'Щодня',
		'Totobi Prom YML is checked on this interval. If the catalog date has not changed, the automatic sync is skipped.' => 'Prom YML Totobi перевіряється з цим інтервалом. Якщо дата каталогу не змінилася, автоматична синхронізація пропускається.',
		'Product update mode' => 'Режим оновлення товарів',
		'Products are published automatically. Images are imported from Totobi and unchanged products are skipped.' => 'Товари публікуються автоматично. Зображення імпортуються з Totobi, а незмінені товари пропускаються.',
		'Save settings' => 'Зберегти налаштування',
		'Manual sync' => 'Ручна синхронізація',
		'Starts the Totobi update now. Progress is shown live while product data and images are processed in small batches.' => 'Запускає оновлення Totobi зараз. Прогрес показується наживо, поки дані товарів і зображення обробляються малими пакетами.',
		'Start sync' => 'Запустити синхронізацію',
		'Pause' => 'Пауза',
		'Resume' => 'Продовжити',
		'Reset sync' => 'Скинути синхронізацію',
		'Waiting to start' => 'Очікування запуску',
		'processed' => 'оброблено',
		'simple updated' => 'простих оновлено',
		'variable updated' => 'варіативних оновлено',
		'variations updated' => 'варіацій оновлено',
		'images downloaded' => 'зображень завантажено',
		'unchanged skipped' => 'незмінених пропущено',
		'products unchanged skipped' => 'товарів без змін пропущено',
		'missing set out of stock' => 'відсутніх немає в наявності',
		'errors' => 'помилок',
		'Last sync' => 'Остання синхронізація',
		'Short result from the most recent import run.' => 'Короткий результат останнього запуску імпорту.',
		'Recent log' => 'Останні події',
		'Status' => 'Статус',
		'Finished' => 'Завершено',
		'Catalog date' => 'Дата каталогу',
		'Created' => 'Створено',
		'Updated' => 'Оновлено',
		'Unchanged' => 'Без змін',
		'Out of stock' => 'Немає в наявності',
		'Images' => 'Зображення',
		'Download updated products CSV' => 'Завантажити CSV оновлених товарів',
		'Errors' => 'Помилки',
		'No log entries yet.' => 'Записів журналу ще немає.',
		'Log' => 'Журнал',
		'Sync event recorded.' => 'Подію синхронізації записано.',
		'Feed check started.' => 'Перевірку фіда розпочато.',
		'Feed check completed.' => 'Перевірку фіда завершено.',
		'Could not download Totobi feed.' => 'Не вдалося завантажити фід Totobi.',
		'Could not read Totobi feed.' => 'Не вдалося прочитати фід Totobi.',
		'Could not read product list.' => 'Не вдалося прочитати список товарів.',
		'Automatic sync skipped because Totobi data did not change.' => 'Автоматичну синхронізацію пропущено: дані Totobi не змінилися.',
		'Completed' => 'Завершено',
		'Skipped' => 'Пропущено',
		'Error' => 'Помилка',
		'No sync yet' => 'Синхронізації ще не було',
		'Settings saved.' => 'Налаштування збережено.',
		'Manual sync check completed.' => 'Перевірку ручної синхронізації завершено.',
		'Manual sync check failed. See log for details.' => 'Перевірка ручної синхронізації не вдалася. Деталі в журналі.',
		'Cannot load WooCommerce categories.' => 'Не вдалося завантажити категорії WooCommerce.',
		'Totobi ID' => 'ID Totobi',
		'Totobi category' => 'Категорія Totobi',
		'Client path' => 'Шлях клієнта',
		'WooCommerce category' => 'Категорія WooCommerce',
		'Do not assign' => 'Не призначати',
		'Insufficient permissions.' => 'Недостатньо прав.',
		'Preparing Totobi feed...' => 'Підготовка фіда Totobi...',
		'Cannot start sync' => 'Не вдалося запустити синхронізацію',
		'Sync started. Processing first batch...' => 'Синхронізацію запущено. Обробляється перший пакет...',
		'Sync started. Products: %total%, catalog: %catalog%' => 'Синхронізацію запущено. Товарів: %total%, каталог: %catalog%',
		'AJAX start request failed' => 'AJAX-запит запуску не вдався',
		'Processing next batch...' => 'Обробка наступного пакета...',
		'Batch failed' => 'Пакет не оброблено',
		'ERROR:' => 'ПОМИЛКА:',
		'Sync completed.' => 'Синхронізацію завершено.',
		'Batch request failed. Retry %count% in 5 seconds...' => 'Запит пакета не вдався. Повтор %count% через 5 секунд...',
		'Batch request failed. Retrying...' => 'Запит пакета не вдався. Повтор...',
		'Sync paused.' => 'Синхронізацію призупинено.',
		'Sync resumed.' => 'Синхронізацію продовжено.',
		'Sync resumed. Processing next batch...' => 'Синхронізацію продовжено. Обробляється наступний пакет...',
		'simple products' => 'прості товари',
		'variable products' => 'варіативні товари',
		'missing products' => 'відсутні товари',
		'Processing %stage%: %processed% of %total%' => 'Обробка: %stage%, %processed% із %total%',
		'Reset the current sync session?' => 'Скинути поточну сесію синхронізації?',
		'Sync session reset.' => 'Сесію синхронізації скинуто.',
		'Sync was interrupted. Continue?' => 'Синхронізацію було перервано. Продовжити?',
	),
);

foreach ( $translations as $locale => $messages ) {
	$po = 'msgid ""' . "\n";
	$po .= 'msgstr ""' . "\n";
	$po .= '"Project-Id-Version: Woo Totobi Integration 0.1.0\n"' . "\n";
	$po .= '"Content-Type: text/plain; charset=UTF-8\n"' . "\n";
	$po .= '"Content-Transfer-Encoding: 8bit\n"' . "\n\n";

	foreach ( $messages as $id => $str ) {
		$po .= 'msgid "' . po_escape( $id ) . '"' . "\n";
		$po .= 'msgstr "' . po_escape( $str ) . '"' . "\n\n";
	}

	$base = __DIR__ . '/../languages/woo-totobi-integration-' . $locale;
	file_put_contents( $base . '.po', $po );
	file_put_contents( $base . '.mo', build_mo( $messages ) );
}

function po_escape( $value ) {
	return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
}

function build_mo( $messages ) {
	$ids      = array_keys( $messages );
	$strings  = array_values( $messages );
	$count    = count( $messages );
	$offset_o = 28;
	$offset_t = $offset_o + $count * 8;
	$offset_s = $offset_t + $count * 8;
	$original = '';
	$trans    = '';
	$otable   = '';
	$ttable   = '';

	foreach ( $ids as $id ) {
		$bytes = $id . "\0";
		$otable .= pack( 'VV', strlen( $id ), $offset_s + strlen( $original ) );
		$original .= $bytes;
	}

	$offset_s += strlen( $original );
	foreach ( $strings as $string ) {
		$bytes = $string . "\0";
		$ttable .= pack( 'VV', strlen( $string ), $offset_s + strlen( $trans ) );
		$trans .= $bytes;
	}

	return pack( 'V*', 0x950412de, 0, $count, $offset_o, $offset_t, 0, 0 ) . $otable . $ttable . $original . $trans;
}
