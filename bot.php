<?php
// ملف الإعدادات
$token = '7442427853:AAFkp3zX3oE-818GeqcFfZKyNx_Vhn61mis';
$admin_id = '8381375458';
$bot_status = 'on'; // حالة البوت (on/off)
$main_admin = '8381375458'; // الأدمن الأساسي (المالك)

// تعريف الثوابت بمسارات مطلقة لضمان الحفظ والقراءة الصحيحة
define("BASE_DIR", __DIR__ . DIRECTORY_SEPARATOR);
define("BALANCES_FILE", BASE_DIR . "balances00.json");
define("STEPS_DIR", BASE_DIR . "steps" . DIRECTORY_SEPARATOR);
define("PRICES_FILE", BASE_DIR . "prices.json");
define("CASH_FILE", BASE_DIR . "cash.txt");
define("USERS_FILE", BASE_DIR . "users.json");
define("BANNED_FILE", BASE_DIR . "banned.json");
define("ADMINS_FILE", BASE_DIR . "admins.json");
define("FORCED_CHANNELS_FILE", BASE_DIR . "forced_channels.json");
define("DATA_TRANS_DIR", BASE_DIR . "data_trans" . DIRECTORY_SEPARATOR);

// إنشاء المجلدات اللازمة
$directories = [STEPS_DIR, DATA_TRANS_DIR];
foreach ($directories as $dir) {
    if (!file_exists($dir) && !mkdir($dir, 0755, true)) {
        error_log("Failed to create directory: $dir");
        exit("Failed to create required directories");
    }
}

/**
 * دالة آمنة لتهيئة الملفات والتأكد من صحة محتوى JSON.
 */
function safe_init_file($file, $default = []) {
    if (!file_exists($file)) {
        if (file_put_contents($file, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            error_log("Failed to create file: $file");
            return false;
        }
        return true;
    }
    
    $content = file_get_contents($file);
    if ($content === false) {
        error_log("Failed to read file: $file");
        return false;
    }
    
    json_decode($content);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Corrupted JSON file: $file. Re-initializing.");
        if (file_put_contents($file, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            error_log("Failed to re-initialize file: $file");
            return false;
        }
    }
    
    return true;
}

// تهيئة الملفات
$files_to_init = [
    BALANCES_FILE => [],
    USERS_FILE => [],
    BANNED_FILE => [],
    ADMINS_FILE => [$admin_id],
    FORCED_CHANNELS_FILE => []
];

foreach ($files_to_init as $file => $default) {
    if (!safe_init_file($file, $default)) {
        error_log("Critical error: Failed to initialize $file");
        exit("Failed to initialize system files");
    }
}

// تهيئة ملف الأسعار
if (!file_exists(PRICES_FILE)) {
    $default_prices = [
        "💎 110" => 8700, "💎 330" => 25000,
        "💎 530" => 39000, "💎 1080" => 74000,
        "💎 2180" => 145000,
        "العضوية الأسبوعية" => 9000, "العضوية الشهرية" => 25000,
        "UC 60" => 8500, "UC 325" => 25000, "UC 660" => 45000,
        "UC 1800" => 120000, "UC 3850" => 235000, "UC 8100" => 460000
    ];
    if (!safe_init_file(PRICES_FILE, $default_prices)) {
        error_log("Failed to initialize prices file");
    }
}

// تهيئة ملف الكاش
if (!file_exists(CASH_FILE)) {
    if (file_put_contents(CASH_FILE, "62324913") === false) {
        error_log("Failed to create cash file");
    }
}

// تحميل البيانات
function load_data($file) {
    if (!file_exists($file)) {
        error_log("File not found: $file");
        return [];
    }
    
    $content = file_get_contents($file);
    if ($content === false) {
        error_log("Failed to read file: $file");
        return [];
    }
    
    $data = json_decode($content, true);
    if (!is_array($data)) {
        error_log("Invalid data in file: $file");
        return [];
    }
    
    return $data;
}

$balances = load_data(BALANCES_FILE);
$prices = load_data(PRICES_FILE);
$users = load_data(USERS_FILE);
$banned = load_data(BANNED_FILE);
$admins = load_data(ADMINS_FILE);
$forced_channels = load_data(FORCED_CHANNELS_FILE);

// استقبال التحديث من Telegram
$update = json_decode(file_get_contents("php://input"), true);
if (empty($update)) {
    exit();
}

$message = $update["message"] ?? null;
$callback = $update["callback_query"] ?? null;
$data = $callback["data"] ?? null;
$text = $message["text"] ?? null;
$cid = $message["chat"]["id"] ?? $callback["message"]["chat"]["id"] ?? null;
$uid = $message["from"]["id"] ?? $callback["from"]["id"] ?? null;

// --- الدوال المساعدة ---
function isMainAdmin($user_id) {
    global $main_admin;
    return $user_id == $main_admin;
}

function checkChannelsSubscription($user_id) {
    global $forced_channels, $token;
    
    if (empty($forced_channels)) return true;
    
    foreach ($forced_channels as $channel) {
        $channel_id = str_replace('@', '', $channel['username']);
        $result = json_decode(file_get_contents("https://api.telegram.org/bot$token/getChatMember?chat_id=@$channel_id&user_id=$user_id"), true);
        
        if (!isset($result['result']['status']) || in_array($result['result']['status'], ['left', 'kicked'])) {
            return false;
        }
    }
    return true;
}

function getBotStatistics() {
    global $users, $balances, $banned, $admins, $forced_channels;
    
    $total_users = count($users);
    $total_banned = count($banned);
    $total_admins = count($admins);
    $total_channels = count($forced_channels);
    
    $total_balance = 0;
    foreach ($balances as $user_id => $user_data) {
        $total_balance += $user_data['balance'] ?? 0;
    }
    
    return [
        'users' => $total_users,
        'banned' => $total_banned,
        'admins' => $total_admins,
        'channels' => $total_channels,
        'balance' => $total_balance
    ];
}

function send($id, $text, $inline = false, $keys = null) {
    global $token;
    $d = ["chat_id" => $id, "text" => $text, "parse_mode" => "Markdown"];
    if ($keys) {
        $markup = $inline ? ["inline_keyboard" => $keys] : ["keyboard" => $keys, "resize_keyboard" => true];
        $d["reply_markup"] = json_encode($markup);
    }
    $result = file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query($d));
    return $result !== false;
}

function answer($cid, $text) {
    global $token;
    $result = file_get_contents("https://api.telegram.org/bot$token/answerCallbackQuery?callback_query_id=$cid&text=" . urlencode($text));
    return $result !== false;
}

function deleteMessage($chat_id, $message_id) {
    global $token;
    $result = file_get_contents("https://api.telegram.org/bot$token/deleteMessage?chat_id=$chat_id&message_id=$message_id");
    return $result !== false;
}

function saveStep($uid, $step) { 
    return file_put_contents(STEPS_DIR . $uid, $step) !== false;
}

function getStep($uid) { 
    return file_exists(STEPS_DIR . $uid) ? file_get_contents(STEPS_DIR . $uid) : null;
}

function delStep($uid) { 
    return file_exists(STEPS_DIR . $uid) ? unlink(STEPS_DIR . $uid) : false;
}

function saveData($file, $data) {
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

// ----------------------------------------------------
// بداية الدالة الجديدة لمعالجة منطق التحديثات
function handle_update_logic($input_text, $input_data, $input_cid, $input_uid, $input_callback = null) {
    global $token, $admin_id, $bot_status, $main_admin;
    global $balances, $prices, $users, $banned, $admins, $forced_channels;
    global $BALANCES_FILE, $PRICES_FILE, $USERS_FILE, $BANNED_FILE, $ADMINS_FILE, $FORCED_CHANNELS_FILE, $CASH_FILE, $DATA_TRANS_DIR;

    $text = $input_text;
    $data = $input_data;
    $cid = $input_cid;
    $uid = $input_uid;
    $callback = $input_callback;

    // التحقق من حالة البوت
    if ($bot_status == 'off' && !in_array($uid, $admins)) {
        if ($text == '/start') {
            // السماح ببدء المحادثة
        } else {
            send($cid, "⚠️ البوت متوقف حاليًا للصيانة. سنعود قريبًا!", false, [
                [["text" => "🔄 تحديث", "callback_data" => "check_bot_status"]]
            ]);
            return; 
        }
    }

    // التحقق من المستخدم المحظور
    if (in_array($uid, $banned)) {
        send($cid, "🚫 تم حظرك من استخدام البوت. للاستفسار راسل الدعم.");
        return; 
    }

    // التحقق من الاشتراك في القنوات الإجبارية
    if ($text == "/start" && !in_array($uid, $admins)) {
        if (!checkChannelsSubscription($uid)) {
            $channels_list = "";
            $buttons = [];
            foreach ($forced_channels as $channel) {
                $channels_list .= "- @{$channel['username']}\n";
                $buttons[] = [["text" => "انضمام إلى @{$channel['username']}", "url" => "https://t.me/{$channel['username']}"]];
            }
            
            $buttons[] = [["text" => "✅ تحقق من الاشتراك", "callback_data" => "check_subscription"]];
            
            send($cid, "📢 يرجى الاشتراك في القنوات التالية لاستخدام البوت:\n$channels_list", true, $buttons);
            return; 
        }
    }

    // معالجة التحقق من الاشتراك
    if ($data == "check_subscription") {
        if (checkChannelsSubscription($uid)) {
            answer($callback["id"], "✅ تم التحقق من اشتراكك في جميع القنوات");
            deleteMessage($callback["message"]["chat"]["id"], $callback["message"]["message_id"]);
            handle_update_logic("/start", null, $cid, $uid);
        } else {
            answer($callback["id"], "❌ لم تشترك في جميع القنوات المطلوبة");
        }
        return;
    }

    // معالجة التحقق من حالة البوت
    if ($data == "check_bot_status") {
        global $bot_status;
        if ($bot_status == 'on') {
            answer($callback["id"], "✅ البوت يعمل الآن");
            deleteMessage($callback["message"]["chat"]["id"], $callback["message"]["message_id"]);
            handle_update_logic("/start", null, $cid, $uid);
        } else {
            answer($callback["id"], "⚠️ البوت لا يزال متوقفًا");
        }
        return;
    }

    // إنشاء سجل للمستخدم الجديد
    if (!isset($balances[$uid])) {
        $balances[$uid] = ["balance" => 0, "spend" => 0];
        saveData(BALANCES_FILE, $balances);
    }
    
    if (!in_array($uid, $users)) {
        $users[] = $uid;
        saveData(USERS_FILE, $users);
    }

    // بدء البوت
    if ($text == "/start") {
        if ($bot_status == 'off' && !in_array($uid, $admins)) {
            send($cid, "⚠️ البوت متوقف حاليًا للصيانة. سنعود قريبًا!", false, [
                [["text" => "🔄 تحديث", "callback_data" => "check_bot_status"]]
            ]);
            return; 
        }
        
        $start_buttons = [
            [["text" => "FREE FIRE 💎"], ["text" => "PUBG ⚜️"]],
            [["text" => "شحن رصيدي 💸"], ["text" => "معلومات الحساب 👤"]],
            [["text" => "🚨 المساعدة والدعم 🚨"]]
        ];
        
        if (in_array($uid, $admins)) {
            $start_buttons[] = [["text" => "/admin"]];
            $start_buttons[] = [["text" => "📊 إحصائيات البوت"]];
            $start_buttons[] = [["text" => $bot_status == 'on' ? "⏹️ إيقاف البوت" : "▶️ تشغيل البوت"]];
        }
        
        send($cid, "♕     اخـتـر مـن أحـد الأوامـر الـتـالـيـة     ♕ :", false, $start_buttons);
    }

    // التحقق من صلاحيات الأدمن
    if ($text == "/admin") {
        if (!in_array($uid, $admins)) {
            send($cid, "عذراً، هذا الأمر متاح فقط للإدمن.");
            return; 
        }
        
        $admin_buttons = [
            [["text" => "➕ إضافة رصيد"], ["text" => "➖ خصم رصيد"]],
            [["text" => "💵 تعديل الأسعار"], ["text" => "🔁 تغيير رقم الكاش"]],
            [["text" => "📢 إرسال إذاعة"], ["text" => "🚫 حظر مستخدم"]],
            [["text" => "✅ فك حظر مستخدم"]]
        ];
        
        if (isMainAdmin($uid)) {
            $admin_buttons[] = [["text" => "👨‍💼 إضافة أدمن"], ["text" => "👨‍💼 حذف أدمن"]];
            $admin_buttons[] = [["text" => "📢 إدارة الاشتراك الإجباري"]];
        }
        
        $admin_buttons[] = [["text" => "📊 إحصائيات البوت"]];
        $admin_buttons[] = [["text" => $bot_status == 'on' ? "⏹️ إيقاف البوت" : "▶️ تشغيل البوت"]];
        
        send($cid, " اهـــلا بـــك ايــهـا الادمــن ", false, $admin_buttons);
    }

    // الأوامر العامة
    if ($text == "🚨 المساعدة والدعم 🚨") {
        send($cid, "اهـلا وسـهـلا تـفـضـل اطـرح الـمـشـكـلـه الـتـي تـواجـهـك 🌔 : \nرقم الواتساب: [يرجى إدخال رقم الواتساب هنا]");
    }

    if ($text == "معلومات الحساب 👤") {
        $first_name = $callback['from']['first_name'] ?? $message['from']['first_name'] ?? "مستخدم";
        $last_name = $callback['from']['last_name'] ?? $message['from']['last_name'] ?? "";
        $full_name = trim("$first_name $last_name");
        
        $balance = $balances[$uid]["balance"] ?? 0;
        $spend = $balances[$uid]["spend"] ?? 0;
        $credit = number_format($balance / 15000, 4);

        $info_message = "👾 *معلومات حسابي* 👾\n";
        $info_message .= "🔆 *الاسم:* [$full_name](tg://user?id=$uid)\n";
        $info_message .= "🔆 *ايدي حسابك:* `$uid`\n";
        $info_message .= "🔆 `$credit` الرصيد بـ CREDIT\n";
        $info_message .= "🔆 `".number_format($balance)."` رصيدك بـ اليرة السورية\n";
        $info_message .= "🔆  ليرة سورية`".number_format($spend)."` إجمالي المصروفات\n";

        send($cid, $info_message);
    }

    // قسم الألعاب
    if ($text == "FREE FIRE 💎") {
        $keys = [[["text" => "FREE FIRE AUTO", "callback_data" => "show_categories:FF:manual"]]];
        send($cid, "🔆 اللعبة FREE FIRE\n\n🔆 اختر سيرفر الشحن المناسب :", true, $keys);
    }

    if ($text == "PUBG ⚜️") {
        $keys = [[["text" => "PUBG AUTO", "callback_data" => "show_categories:PUBG:manual"]]];
        send($cid, "🔆 اللعبة PUBG\n\n🔆 اختر سيرفر الشحن المناسب :", true, $keys);
    }

    // معالجة الكال باك
    if ($data) {
        // عرض رقم الكاش
        if ($data == "show_cash_number") {
            $cash_number = file_get_contents(CASH_FILE);
            $copyable_code = "`$cash_number`";
            
            send($cid, "*syriatel cash ( تلقائي )*\nقم بإيداع المبلغ المطلوب على أحد الرموز التالية عن طريق التحويل اليدوي ولسنا مسؤولين عن أية عملية تعبئة رصيد (وحدات):\n\n$copyable_code\n\nعلماً أنَّ:\n\n1 CREADIT = 10400 ل.س\n\n-------------------------\n\nأدخل رقم عملية التحويل:", false);
            
            saveStep($uid, "wait_trans_id");
            answer($callback["id"], "تم عرض رقم الكاش");
        }
        
        // معالجة اختيار الفئة
        if (strpos($data, "show_categories:") === 0) {
            list(, $game, $type) = explode(":", $data);
            
            $keys = [];
            foreach ($prices as $name => $price) {
                if (($game == "FF" && (strpos($name, "💎") !== false || strpos($name, "Membership") !== false)) ||
                    ($game == "PUBG" && strpos($name, "UC") !== false)) {
                    $keys[] = [["text" => "$name - " . number_format($price) . " ل.س", "callback_data" => "show_details:$game:$name"]];
                }
            }
            
            send($cid, "$game AUTO\nاختر حزمة :", true, $keys);
            answer($callback["id"], "تم عرض الفئات");
        }
        
        // معالجة اختيار الحزمة
        if (strpos($data, "show_details:") === 0) {
            list(, $game, $pack) = explode(":", $data);
            $price = $prices[$pack];
            
            deleteMessage($callback["message"]["chat"]["id"], $callback["message"]["message_id"]);
            
            send($cid, "♕ تفاصيل الحزمة ♕:\n\n♪ اللعبة: $game ( اوتوماتيكي ) \n♪ الفئة: $pack\n♪ السعر: " . number_format($price) . " ل.س\n\nاختر طريقة الشحن 👇👇:", true, [
                [["text" => "عن طريق الـID", "callback_data" => "enter_id:$game:$pack"]],
                [["text" => "تغيير السيرفر", "callback_data" => "back_to_games:$game"]]
            ]);
            answer($callback["id"], "تم عرض التفاصيل");
        }
        
        // معالجة إدخال ID
        if (strpos($data, "enter_id:") === 0) {
            list(, $game, $pack) = explode(":", $data);
            saveStep($uid, "wait_game_id:$game:$pack");
            
            deleteMessage($callback["message"]["chat"]["id"], $callback["message"]["message_id"]);
            
            send($cid, "يرجى إرسال ID حسابك :");
            answer($callback["id"], "انتظر إدخال ID");
        }
        
        // تأكيد الطلب
        if (strpos($data, "confirm_order:") === 0) {
            list(, $game, $pack, $player_id) = explode(":", $data);
            $price = $prices[$pack];
            
            deleteMessage($callback["message"]["chat"]["id"], $callback["message"]["message_id"]);
            
            if ($balances[$uid]["balance"] < $price) {
                send($cid, "❌ رصيدك غير كافي. يرجى شحن الرصيد أولاً.");
                return;
            }
            
            $balances[$uid]["balance"] -= $price;
            $balances[$uid]["spend"] += $price;
            
            if (!saveData(BALANCES_FILE, $balances)) {
                send($cid, "❌ حدث خطأ داخلي عند تحديث رصيدك. يرجى المحاولة مرة أخرى لاحقاً.");
                return;
            }
            
            $order_id = uniqid();
            $now = time();
            $price_usd = number_format($price / 15000, 2);
            $price_credit = number_format($price / 15000, 4);
            
            $order_data = [
                "game" => $game, "pack" => $pack, "price_usd" => $price_usd,
                "price_lira" => $price, "price_credit" => $price_credit,
                "player_id" => $player_id, "user_id" => $uid,
                "time" => $now
            ];
            
            if (!saveData(DATA_TRANS_DIR . "order_$order_id.json", $order_data)) {
                send($cid, "❌ حدث خطأ داخلي عند إنشاء طلبك. يرجى المحاولة مرة أخرى لاحقاً.");
                return;
            }
            
            send($cid, "هذه خدمة آلية سوف يتم تنفيذ طلبك خلال دقيقة ✅\n\n♕ رقم الطلب: $order_id\n♕ اللعبة: $game\n♕ الحزمة: $pack\n♕ السعر: $price_usd $\n♕ السعر بالليرة: " . number_format($price) . " ل.س\n♕ آيدى اللاعب: $player_id\n\n♕ سيتم تنفيذ الطلب خلال (1 ثانية - 3 دقائق )");
            
            send($admin_id, "🎮 طلب شحن جديد:\n\n⨗ معرف الطلب: $order_id\n⨗ اللعبة: $game\n⨗ الفئة: $pack\n⨗ السعر: $price_credit credits\n⨗ معرف اللاعب: $player_id\n⨗ من: $uid", true, [
                [["text" => "✅ تم الشحن", "callback_data" => "okorder:$order_id"]],
                [["text" => "❌ لن يتم الشحن", "callback_data" => "rejectorder:$order_id"]]
            ]);
            
            answer($callback["id"], "تم تأكيد الطلب");
        }
        
        // موافقة الأدمن على الطلب
        if (strpos($data, "okorder:") === 0) {
            $order_id = explode(":", $data)[1];
            $data_file = DATA_TRANS_DIR . "order_$order_id.json";
            
            if (!file_exists($data_file)) {
                answer($callback["id"], "❌ الطلب غير موجود أو تم معالجته مسبقًا.");
                return;
            }
            
            $order = load_data($data_file);
            $time_diff = time() - $order["time"];
            $mins = floor($time_diff / 60);
            $secs = $time_diff % 60;
            
            $msg = "تم اكتمال طلبك اوتوماتيكيا بنجاح ✅️\n\n✓ رقم الطلب : $order_id\n✓ اللعبة: {$order["game"]}\n✓ الحزمة : {$order["pack"]}\n✓ السعر: {$order["price_credit"]} credits\n✓ معرف اللاعب: {$order["player_id"]}\n\n⏱️ الوقت المستغرق: {$mins} دقائق و {$secs} ثانية ";
            
            send($order["user_id"], $msg);
            answer($callback["id"], "✅ تم الشحن");
            unlink($data_file);
        }
        
        // رفض الأدمن للطلب
        if (strpos($data, "rejectorder:") === 0) {
            $order_id = explode(":", $data)[1];
            $data_file = DATA_TRANS_DIR . "order_$order_id.json";
            
            if (!file_exists($data_file)) {
                answer($callback["id"], "❌ الطلب غير موجود أو تم معالجته مسبقًا.");
                return;
            }
            
            $order = load_data($data_file);
            
            // إعادة الرصيد للمستخدم عند الرفض
            if (isset($balances[$order["user_id"]])) {
                $balances[$order["user_id"]]["balance"] += $order["price_lira"];
                saveData(BALANCES_FILE, $balances);
            }

            $time_diff = time() - $order["time"];
            $h = floor($time_diff / 3600);
            $m = floor(($time_diff % 3600) / 60);
            $s = $time_diff % 60;
            
            $msg = "تم انتهاء الكمية ولن نستطيع تنفيذ طلبك اوتوماتيكيا ❌️. تم إعادة الرصيد إلى حسابك.\n▪️ معرف الطلب: $order_id\n▪️ اللعبة: {$order["game"]}\n▪️ الحزمة: {$order["pack"]}\n▪️ السعر: {$order["price_usd"]} $\n▪️ معرف اللاعب: {$order["player_id"]}\n\n⏱️ الوقت المستغرق: {$h} ساعات و {$m} دقائق و {$s} ثانية";
            
            send($order["user_id"], $msg);
            answer($callback["id"], "❌ تم الرفض وإعادة الرصيد.");
            unlink($data_file);
        }
        
        // إضافة رصيد من قبل الأدمن
        if (strpos($data, "add:") === 0) {
            $parts = explode(":", $data);
            $tid = $parts[1];
            $amount = isset($parts[2]) ? intval($parts[2]) : 0;
            
            if (!is_numeric($tid) || $amount <= 0) {
                answer($callback["id"], "❌ بيانات غير صحيحة.");
                return;
            }

            if (!isset($balances[$tid])) {
                $balances[$tid] = ["balance" => 0, "spend" => 0];
            }
            
            $balances[$tid]["balance"] += $amount;
            
            if (!saveData(BALANCES_FILE, $balances)) {
                answer($callback["id"], "❌ حدث خطأ عند إضافة الرصيد.");
                return;
            }
            
            send($tid, "تم التحقق من العمليه بنجاح ✅\nتمت اضافة $amount ليرة سورية إلى حسابك");
            answer($callback["id"], "✅ تمت الإضافة.");
        }
        
        // تعديل الأسعار من قبل الأدمن
        if (strpos($data, "setprice:") === 0) {
            $pack = explode(":", $data)[1];
            saveStep($uid, "price|$pack");
            send($cid, "💵 أرسل السعر الجديد لـ $pack:");
        }
        
        // إدارة القنوات الإجبارية
        if (strpos($data, "forced_channels_") === 0) {
            if (!isMainAdmin($uid)) {
                answer($callback["id"], "⛔ ليس لديك صلاحية الوصول لهذه الميزة");
                return;
            }
            
            if ($data == "forced_channels_add") {
                saveStep($uid, "wait_channel_username");
                send($cid, "أرسل معرف القناة (مثال: @channel) لإضافتها للاشتراك الإجباري:");
            }
            elseif ($data == "forced_channels_remove") {
                if (empty($forced_channels)) {
                    send($cid, "❌ لا توجد قنوات مسجلة حالياً");
                    return;
                }
                
                $buttons = [];
                foreach ($forced_channels as $index => $channel) {
                    $buttons[] = [
                        ["text" => $channel['username'], "callback_data" => "show_channel:$index"],
                        ["text" => "🗑️ حذف", "callback_data" => "forced_channel_delete:$index"]
                    ];
                }
                $buttons[] = [["text" => "🔙 رجوع", "callback_data" => "forced_channels_back"]];
                
                send($cid, "📋 قائمة القنوات الإجبارية:", true, $buttons);
            }
            elseif ($data == "forced_channels_back") {
                handle_update_logic("/admin", null, $cid, $uid, $callback);
            }
            elseif (strpos($data, "forced_channel_delete:") === 0) {
                $index = explode(":", $data)[1];
                if (isset($forced_channels[$index])) {
                    $deleted_channel = $forced_channels[$index]['username'];
                    unset($forced_channels[$index]);
                    $forced_channels = array_values($forced_channels);
                    
                    if (saveData(FORCED_CHANNELS_FILE, $forced_channels)) {
                        send($cid, "✅ تم حذف القناة @$deleted_channel بنجاح");
                    } else {
                        send($cid, "❌ حدث خطأ أثناء حذف القناة");
                    }
                }
            }
            
            answer($callback["id"], "تمت المعالجة");
        }
    }

    // معالجة الخطوات
    if ($step = getStep($uid)) {
        // انتظار إدخال ID اللعبة
        if (strpos($step, "wait_game_id:") === 0) {
            if (!is_numeric($text)) {
                send($cid, "❌ يجب إدخال أرقام فقط. الرجاء المحاولة مرة أخرى من البداية.");
                delStep($uid);
                return;
            }
            
            list(, $game, $pack) = explode(":", $step);
            $price = $prices[$pack];
            
            send($cid, "♕ تفاصيل الطلب ♕:\n\n✽ اللعبة: $game\n✽ الفئة: $pack\n✽ السعر: " . number_format($price) . " ل.س\n\nID الحساب: $text\n\nيرجى التأكد من الآيدي والضغط على تاكيد الطلب", true, [
                [["text" => "تأكيد الطلب", "callback_data" => "confirm_order:$game:$pack:$text"]],
                [["text" => "إلغاء الطلب", "callback_data" => "cancel_order"]]
            ]);
            delStep($uid);
        }
        
        // انتظار رقم التحويل
        elseif ($step == "wait_trans_id") {
            if (!is_numeric($text)) {
                send($cid, "❌ يجب إدخال أرقام فقط. الرجاء المحاولة مرة أخرى من البداية.");
                delStep($uid);
                return;
            }
            
            if (file_put_contents(DATA_TRANS_DIR . "{$uid}_trans_id.txt", $text) === false) {
                send($cid, "❌ حدث خطأ داخلي. يرجى المحاولة مرة أخرى.");
                delStep($uid);
                return;
            }
            
            saveStep($uid, "wait_amount");
            send($cid, "الرجاء ادخال المبلغ ( بالارقام فقط ) ");
        } 
        
        // انتظار المبلغ المحول
        elseif ($step == "wait_amount") {
            if (!is_numeric($text)) {
                send($cid, "❌ يجب إدخال أرقام فقط. الرجاء المحاولة مرة أخرى من البداية.");
                delStep($uid);
                return;
            }
            
            $trans_id_file = DATA_TRANS_DIR . "{$uid}_trans_id.txt";
            if (!file_exists($trans_id_file)) {
                 send($cid, "❌ حدث خطأ: لا يمكن العثور على رقم العملية. يرجى البدء من جديد.");
                 delStep($uid);
                 return;
            }
            
            $trans_id = file_get_contents($trans_id_file);
            $amount = intval($text);
            
            if ($amount <= 0) {
                 send($cid, "❌ المبلغ يجب أن يكون رقمًا موجبًا. الرجاء المحاولة مرة أخرى.");
                 delStep($uid);
                 return;
            }

            unlink($trans_id_file);
            delStep($uid);

            $confirm_buttons = [
                [["text" => "✅ تأكيد الايداع", "callback_data" => "add:$uid:$amount"]],
                [["text" => "❌ رفض الايداع", "callback_data" => "reject:$uid"]]
            ];
            
            send($admin_id, "طلب ايداع سيريتل كاش تلقائي:\n🔹 رقم المستخدم: $uid\n🔹 رقم العملية: $trans_id\n🔹 المبلغ: " . number_format($amount) . " ل.س", true, $confirm_buttons);
            send($cid, "⚠️ لم يتم العثور على العملية تلقائياً.\nتم إرسال طلبك للتحقق اليدوي من قبل الفريق.\nســيــتــم ارســال الــرد إلــيــك عــند اتــمــام الــعمــليــه!");
        }
        
        // الإذاعة
        elseif ($step == "broadcast") {
            if (!in_array($uid, $admins)) {
                send($cid, "⛔ ليس لديك صلاحية تنفيذ هذا الأمر!");
                delStep($uid);
                return;
            }
            
            $broadcast_sent = false;
            foreach ($users as $u) {
                if (!in_array($u, $banned)) {
                    if (send($u, "📢 رسالة من الأدمن:\n$text")) {
                        $broadcast_sent = true;
                    }
                }
            }
            
            send($cid, $broadcast_sent ? "✅ تم إرسال الإذاعة." : "⚠️ لم يتم إرسال الإذاعة لأي مستخدم.");
            delStep($uid);
        } 
        
        // تغيير رقم الكاش
        elseif ($step == "setcash") {
            if (!in_array($uid, $admins)) {
                send($cid, "⛔ ليس لديك صلاحية تنفيذ هذا الأمر!");
                delStep($uid);
                return;
            }
            
            if (file_put_contents(CASH_FILE, $text) === false) {
                send($cid, "❌ حدث خطأ عند تغيير رقم الكاش.");
            } else {
                send($cid, "✅ تم تغيير رقم الكاش.");
            }
            delStep($uid);
        } 
        
        // تعديل السعر
        elseif (strpos($step, "price|") === 0) {
            if (!in_array($uid, $admins)) {
                send($cid, "⛔ ليس لديك صلاحية تنفيذ هذا الأمر!");
                delStep($uid);
                return;
            }
            
            $p = explode("|", $step)[1];
            if (!is_numeric($text) || intval($text) <= 0) {
                send($cid, "❌ السعر يجب أن يكون رقمًا موجبًا. الرجاء المحاولة مرة أخرى.");
                return;
            }
            
            $prices[$p] = intval($text);
            
            if (saveData(PRICES_FILE, $prices)) {
                send($cid, "✅ تم تعديل سعر $p.");
            } else {
                send($cid, "❌ حدث خطأ عند تعديل السعر.");
            }
            delStep($uid);
        } 
        
        // إضافة رصيد (من الأدمن)
        elseif ($step == "addr") {
            if (!in_array($uid, $admins)) {
                send($cid, "⛔ ليس لديك صلاحية تنفيذ هذا الأمر!");
                delStep($uid);
                return;
            }
            
            if (!strpos($text, ':')) {
                send($cid, "❌ صيغة غير صحيحة. الرجاء استخدام الصيغة: ID:المبلغ");
                return;
            }
            
            list($id, $am) = explode(":", $text);
            if (!is_numeric($am) || intval($am) <= 0) {
                send($cid, "❌ يجب أن يكون المبلغ رقمًا موجبًا. الرجاء المحاولة مرة أخرى.");
                return;
            }
            
            if (!isset($balances[$id])) {
                $balances[$id] = ["balance" => 0, "spend" => 0];
            }
            
            $balances[$id]["balance"] += intval($am);
            
            if (saveData(BALANCES_FILE, $balances)) {
                send($cid, "✅ تم الإضافة.");
            } else {
                send($cid, "❌ حدث خطأ عند إضافة الرصيد.");
            }
            delStep($uid);
        } 
        
        // خصم رصيد (من الأدمن)
        elseif ($step == "subr") {
            if (!in_array($uid, $admins)) {
                send($cid, "⛔ ليس لديك صلاحية تنفيذ هذا الأمر!");
                delStep($uid);
                return;
            }
            
            if (!strpos($text, ':')) {
                send($cid, "❌ صيغة غير صحيحة. الرجاء استخدام الصيغة: ID:المبلغ");
                return;
            }
            
            list($id, $am) = explode(":", $text);
            if (!is_numeric($am) || intval($am) <= 0) {
                send($cid, "❌ يجب أن يكون المبلغ رقمًا موجبًا. الرجاء المحاولة مرة أخرى.");
                return;
            }
            
            if (!isset($balances[$id])) {
                send($cid, "❌ المستخدم غير موجود في قاعدة البيانات.");
                delStep($uid);
                return;
            }
            
            $balances[$id]["balance"] -= intval($am);
            
            if (saveData(BALANCES_FILE, $balances)) {
                send($cid, "✅ تم الخصم.");
            } else {
                send($cid, "❌ حدث خطأ عند خصم الرصيد.");
            }
            delStep($uid);
        } 
        
        // حظر مستخدم (من الأدمن)
        elseif ($step == "ban_user") {
            if (!in_array($uid, $admins)) {
                send($cid, "⛔ ليس لديك صلاحية تنفيذ هذا الأمر!");
                delStep($uid);
                return;
            }
            
            if (!is_numeric($text)) {
                send($cid, "❌ يجب إدخال معرف مستخدم صحيح (أرقام فقط).");
                return;
            }
            
            if (in_array($text, $banned)) {
                send($cid, "⚠️ هذا المستخدم محظور بالفعل!");
                delStep($uid);
                return;
            }
            
            $banned[] = $text;
            
            if (saveData(BANNED_FILE, $banned)) {
                send($cid, "✅ تم حظر المستخدم: $text");
            } else {
                send($cid, "❌ حدث خطأ عند حظر المستخدم.");
            }
            delStep($uid);
        } 
        
        // فك حظر مستخدم (من الأدمن)
        elseif ($step == "unban_user") {
            if (!in_array($uid, $admins)) {
                send($cid, "⛔ ليس لديك صلاحية تنفيذ هذا الأمر!");
                delStep($uid);
                return;
            }
            
            if (!is_numeric($text)) {
                send($cid, "❌ يجب إدخال معرف مستخدم صحيح (أرقام فقط).");
                return;
            }
            
            if (!in_array($text, $banned)) {
                send($cid, "⚠️ هذا المستخدم غير محظور أصلاً!");
                delStep($uid);
                return;
            }
            
            $banned = array_diff($banned, [$text]);
            $banned = array_values($banned);
            
            if (saveData(BANNED_FILE, $banned)) {
                send($cid, "✅ تم فك حظر المستخدم: $text");
            } else {
                send($cid, "❌ حدث خطأ عند فك حظر المستخدم.");
            }
            delStep($uid);
        } 
        
        // إضافة أدمن (من الأدمن الأساسي)
        elseif ($step == "add_admin") {
            if (!isMainAdmin($uid)) {
                send($cid, "⛔ ليس لديك صلاحية تنفيذ هذا الأمر!");
                delStep($uid);
                return;
            }
            
            if (!is_numeric($text)) {
                send($cid, "❌ يجب إدخال معرف مستخدم صحيح (أرقام فقط).");
                return;
            }
            
            if (in_array($text, $admins)) {
                send($cid, "⚠️ هذا المستخدم مسجل بالفعل كأدمن!");
                delStep($uid);
                return;
            }
            
            $admins[] = $text;
            
            if (saveData(ADMINS_FILE, $admins)) {
                send($cid, "✅ تمت ترقية المستخدم $text إلى أدمن بنجاح");
            } else {
                send($cid, "❌ حدث خطأ عند إضافة الأدمن.");
            }
            delStep($uid);
        } 
        
        // إزالة أدمن (من الأدمن الأساسي)
        elseif ($step == "remove_admin") {
            if (!isMainAdmin($uid)) {
                send($cid, "⛔ ليس لديك صلاحية تنفيذ هذا الأمر!");
                delStep($uid);
                return;
            }
            
            if (!is_numeric($text)) {
                send($cid, "❌ يجب إدخال معرف مستخدم صحيح (أرقام فقط).");
                return;
            }
            
            if (!in_array($text, $admins)) {
                send($cid, "⚠️ هذا المستخدم غير مسجل كأدمن!");
                delStep($uid);
                return;
            }
            
            if ($text == $admin_id) {
                send($cid, "⛔ لا يمكن حذف الأدمن الرئيسي!");
                delStep($uid);
                return;
            }
            
            $admins = array_diff($admins, [$text]);
            $admins = array_values($admins);
            
            if (saveData(ADMINS_FILE, $admins)) {
                send($cid, "✅ تم إزالة المستخدم $text من قائمة الأدمن بنجاح");
            } else {
                send($cid, "❌ حدث خطأ عند إزالة الأدمن.");
            }
            delStep($uid);
        }
        
        // إضافة قناة إجبارية
        elseif ($step == "wait_channel_username") {
            if (!preg_match('/^@[a-zA-Z0_]+$/', $text)) {
                send($cid, "❌ صيغة معرف القناة غير صحيحة. يجب أن تبدأ ب @ وتحتوي على أحرف وأرقام فقط.");
                return;
            }
            
            foreach ($forced_channels as $channel) {
                if ($channel['username'] == $text) {
                    send($cid, "⚠️ هذه القناة مسجلة بالفعل!");
                    delStep($uid);
                    return;
                }
            }
            
            $forced_channels[] = ['username' => $text];
            
            if (saveData(FORCED_CHANNELS_FILE, $forced_channels)) {
                send($cid, "✅ تم إضافة القناة $text للاشتراك الإجباري بنجاح");
            } else {
                send($cid, "❌ حدث خطأ عند إضافة القناة.");
            }
            delStep($uid);
        }
    }

    // أوامر الأدمن
    if (in_array($uid, $admins)) {
        if ($text == "➕ إضافة رصيد") {
            saveStep($uid, "addr");
            send($cid, "اكتب: ID:المبلغ");
        } 
        elseif ($text == "➖ خصم رصيد") {
            saveStep($uid, "subr");
            send($cid, "اكتب: ID:المبلغ");
        } 
        elseif ($text == "💵 تعديل الأسعار") {
            $k = [];
            foreach ($prices as $p => $v) {
                $k[] = [["text" => "$p - " . number_format($v) . " ل.س", "callback_data" => "setprice:$p"]];
            }
            send($cid, "اختر الفئة:", true, $k);
        } 
        elseif ($text == "🔁 تغيير رقم الكاش") {
            saveStep($uid, "setcash");
            send($cid, "أرسل الرقم الجديد:");
        } 
        elseif ($text == "📢 إرسال إذاعة") {
            saveStep($uid, "broadcast");
            send($cid, "أرسل نص الإذاعة:");
        } 
        elseif ($text == "🚫 حظر مستخدم") {
            saveStep($uid, "ban_user");
            send($cid, "أرسل معرف المستخدم للحظر:");
        } 
        elseif ($text == "✅ فك حظر مستخدم") {
            saveStep($uid, "unban_user");
            send($cid, "أرسل معرف المستخدم لفك الحظر:");
        } 
        elseif ($text == "👨‍💼 إضافة أدمن" && isMainAdmin($uid)) {
            saveStep($uid, "add_admin");
            send($cid, "أرسل معرف المستخدم (ID) الذي تريد ترقيته إلى أدمن:");
        } 
        elseif ($text == "👨‍💼 حذف أدمن" && isMainAdmin($uid)) {
            saveStep($uid, "remove_admin");
            send($cid, "أرسل معرف المستخدم (ID) الذي تريد إزالته من قائمة الأدمن:");
        } 
        elseif ($text == "📢 إدارة الاشتراك الإجباري" && isMainAdmin($uid)) {
            $channels_list = "📋 القنوات الإجبارية الحالية:\n";
            if (empty($forced_channels)) {
                $channels_list .= "لا توجد قنوات مسجلة حالياً\n";
            } else {
                foreach ($forced_channels as $channel) {
                    $channels_list .= "- {$channel['username']}\n";
                }
            }
            
            send($cid, $channels_list, true, [
                [["text" => "➕ إضافة قناة", "callback_data" => "forced_channels_add"]],
                [["text" => "➖ حذف قناة", "callback_data" => "forced_channels_remove"]],
                [["text" => "🔙 رجوع", "callback_data" => "forced_channels_back"]]
            ]);
        } 
        elseif ($text == "📊 إحصائيات البوت") {
            $stats = getBotStatistics();
            
            $message = "📊 إحصائيات البوت:\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "👥 عدد المستخدمين: {$stats['users']}\n";
            $message .= "🚫 عدد المحظورين: {$stats['banned']}\n";
            $message .= "👨‍💼 عدد الأدمن: {$stats['admins']}\n";
            $message .= "📢 عدد القنوات الإجبارية: {$stats['channels']}\n";
            $message .= "💰 إجمالي الرصيد: " . number_format($stats['balance']) . " ليرة\n";
            $message .= "━━━━━━━━━━━━━━━━━━━━\n";
            $message .= "📅 آخر تحديث: " . date("Y-m-d H:i:s");
            
            send($cid, $message);
        }
        elseif ($text == "⏹️ إيقاف البوت") {
            global $bot_status;
            $bot_status = 'off';
            send($cid, "⏹️ تم إيقاف البوت بنجاح");
        }
        elseif ($text == "▶️ تشغيل البوت") {
            global $bot_status;
            $bot_status = 'on';
            send($cid, "✅ تم تشغيل البوت بنجاح");
        }
    }
}

// استدعاء الدالة لمعالجة التحديث
handle_update_logic($text, $data, $cid, $uid, $callback);
?>