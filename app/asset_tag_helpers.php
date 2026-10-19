<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const ASSET_TAG_MAX_BULK_QUANTITY = 1000000;
const ASSET_TAG_WORKER_BATCH_SIZE = 250;

function assetTagBatchSize(): int
{
    $configured = filter_var(getenv('ASSET_TAG_BATCH_SIZE') ?: (string)ASSET_TAG_WORKER_BATCH_SIZE,FILTER_VALIDATE_INT);
    return $configured === false ? ASSET_TAG_WORKER_BATCH_SIZE : min(5000,max(1,$configured));
}

function normalizeAssetTagPrefix(string $prefix): string
{
    $prefix = strtoupper(trim($prefix));
    if (strlen($prefix) > 60 || !preg_match('/^[A-Z0-9](?:[A-Z0-9_-]{0,58}[A-Z0-9])?$/D', $prefix)) {
        throw new DomainException('Use a valid asset tag prefix of at most 60 characters.');
    }
    return $prefix;
}

function buildAssetTag(string $prefix, int $number, int $paddingDigits): string
{
    if ($number < 1 || $paddingDigits < 1 || $paddingDigits > 18) {
        throw new DomainException('Asset tag number or padding is outside the supported range.');
    }
    $tag = $prefix . '-' . str_pad((string)$number, $paddingDigits, '0', STR_PAD_LEFT);
    if (strlen($tag) > 100) throw new DomainException('The generated asset tag exceeds 100 characters.');
    return $tag;
}

function buildInternalSerialNumber(string $prefix, int $number): string
{
    $prefix = normalizeAssetTagPrefix($prefix);
    if ($number < 1) throw new DomainException('Internal serial number must be positive.');
    $serial = $prefix . '-' . str_pad((string)$number, 6, '0', STR_PAD_LEFT);
    if (strlen($serial) > 150) throw new DomainException('The generated internal serial number exceeds 150 characters.');
    return $serial;
}

/** Reserve an internal serial range while the caller's transaction is active. */
function reserveInternalSerialRange(PDO $pdo, string $prefix, int $quantity): int
{
    $prefix = normalizeAssetTagPrefix($prefix);
    if ($quantity < 1) throw new DomainException('Internal serial quantity must be positive.');
    $pdo->prepare('INSERT INTO internal_serial_prefix_sequences(prefix,next_number) VALUES(?,1) ON DUPLICATE KEY UPDATE prefix=VALUES(prefix)')->execute([$prefix]);
    $stmt = $pdo->prepare('SELECT next_number FROM internal_serial_prefix_sequences WHERE prefix=? FOR UPDATE');
    $stmt->execute([$prefix]);
    $first = (int)$stmt->fetchColumn();
    if ($first < 1 || $first > PHP_INT_MAX - $quantity) throw new DomainException('No internal serial number range is available for this prefix.');
    buildInternalSerialNumber($prefix, $first + $quantity - 1);
    $pdo->prepare('UPDATE internal_serial_prefix_sequences SET next_number=? WHERE prefix=?')->execute([$first + $quantity, $prefix]);
    return $first;
}

function normalizeManufacturerSerials(array $serials, ?int $expectedQuantity = null): array
{
    $normalized = [];
    $seen = [];
    foreach ($serials as $serial) {
        $serial = trim((string)$serial);
        if ($serial === '') continue;
        if (strlen($serial) > 150) throw new DomainException('Manufacturer serial numbers must be 150 characters or fewer.');
        $key = function_exists('mb_strtolower') ? mb_strtolower($serial, 'UTF-8') : strtolower($serial);
        if (isset($seen[$key])) throw new DomainException('The imported batch contains duplicate manufacturer serial numbers.');
        $seen[$key] = true;
        $normalized[] = $serial;
    }
    if ($expectedQuantity !== null && count($normalized) !== $expectedQuantity) throw new DomainException('Enter exactly one manufacturer serial number for each asset.');
    return $normalized;
}

function parseManufacturerSerialText(string $text): array
{
    return normalizeManufacturerSerials(preg_split('/\R/u', trim($text)) ?: []);
}

/** Parse one-column CSV or XLSX serial lists. A header row is optional. */
function parseManufacturerSerialImport(string $filePath, string $extension): array
{
    $extension = strtolower($extension);
    if (!is_file($filePath) || filesize($filePath) > 10 * 1024 * 1024) throw new DomainException('Choose a serial import file no larger than 10 MB.');
    if ($extension === 'csv') {
        $handle = fopen($filePath, 'rb');
        if (!$handle) throw new DomainException('The CSV serial file could not be read.');
        $values = [];
        $serialColumn = 0;
        $firstRow = true;
        while (($row = fgetcsv($handle)) !== false) {
            if ($firstRow) {
                $firstRow = false;
                foreach ($row as $index=>$header) {
                    $header = preg_replace('/^\xEF\xBB\xBF/','',trim((string)$header));
                    if (preg_match('/^(serial|manufacturer serial|serial number|manufacturer serial number)$/i',$header)) { $serialColumn=$index; continue 2; }
                }
            }
            if (isset($row[$serialColumn])) $values[] = (string)$row[$serialColumn];
        }
        fclose($handle);
        return normalizeManufacturerSerials($values);
    }
    if ($extension !== 'xlsx' || !is_file(__DIR__ . '/vendor/autoload.php')) throw new DomainException('Import a CSV or XLSX file. XLSX import is unavailable on this server.');
    require_once __DIR__ . '/vendor/autoload.php';
    try {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($filePath)->getActiveSheet();
        $values = [];
        $serialColumn = 1;
        $firstRow = true;
        foreach ($sheet->getRowIterator() as $row) {
            if ($firstRow) {
                $firstRow=false;
                foreach($row->getCellIterator() as $index=>$headerCell){
                    if(preg_match('/^(serial|manufacturer serial|serial number|manufacturer serial number)$/i',trim((string)$headerCell->getValue()))){$serialColumn=$headerCell->getColumn();continue 2;}
                }
            }
            $values[] = (string)$sheet->getCell($serialColumn . $row->getRowIndex())->getValue();
        }
        return normalizeManufacturerSerials($values);
    } catch (Throwable $exception) {
        if ($exception instanceof DomainException) throw $exception;
        throw new DomainException('The XLSX serial file could not be read.');
    }
}

function validateAssetTagBulkInput(array $input): array
{
    $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => ASSET_TAG_MAX_BULK_QUANTITY]]);
    $padding = filter_var($input['padding_digits'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 18]]);
    $prefix = normalizeAssetTagPrefix((string)($input['prefix'] ?? ''));
    $startRaw = trim((string)($input['starting_number'] ?? ''));
    $start = null;
    if ($startRaw !== '') {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $startRaw) || (float)$startRaw > PHP_INT_MAX) {
            throw new DomainException('Starting number must be a positive whole number within the supported range.');
        }
        $start = (int)$startRaw;
    }
    if (!$productId || !$quantity || $padding === false) throw new DomainException('Select a product, quantity, and padding width.');
    if ($start !== null && $start > PHP_INT_MAX - $quantity) throw new DomainException('The requested numeric range is too large.');
    $type = (string)($input['registration_type'] ?? 'available');
    if (!in_array($type, ['available', 'reconciliation'], true)) throw new DomainException('Choose available stock or assigned stock reconciliation.');
    $condition = trim((string)($input['asset_condition'] ?? ''));
    if ($condition !== '' && !in_array($condition, ['New', 'Good', 'Fair', 'Poor'], true)) throw new DomainException('Choose a valid asset condition.');
    $location = trim((string)($input['asset_location'] ?? ''));
    if (strlen($location) > 150) throw new DomainException('Location must be 150 characters or fewer.');
    $digits = strlen((string)($start ?? 1));
    $last = ($start ?? 1) + $quantity - 1;
    if (strlen($prefix) + 1 + max($digits, strlen((string)$last), (int)$padding) > 100) throw new DomainException('The generated asset tags exceed the supported length.');
    $serialMode = (string)($input['serial_mode'] ?? 'fill_later');
    if (!in_array($serialMode, ['manual','automatic','fill_later'], true)) throw new DomainException('Choose Manual, Automatic, or Fill Later serial mode.');
    $serialPrefix = null;
    $manufacturerSerials = [];
    if ($serialMode === 'automatic') $serialPrefix = normalizeAssetTagPrefix((string)($input['serial_prefix'] ?? 'SN'));
    if ($serialMode === 'manual') {
        $serialsInput = $input['manufacturer_serials'] ?? [];
        if (is_string($serialsInput)) $serialsInput = parseManufacturerSerialText($serialsInput);
        if (!is_array($serialsInput)) throw new DomainException('Provide manufacturer serial numbers as a list.');
        $manufacturerSerials = normalizeManufacturerSerials($serialsInput, (int)$quantity);
    }
    return [
        'product_id' => (int)$productId, 'prefix' => $prefix, 'starting_number' => $start,
        'quantity' => (int)$quantity, 'padding_digits' => (int)$padding,
        'asset_location' => $location !== '' ? $location : null,
        'asset_condition' => $condition !== '' ? $condition : null,
        'registration_type' => $type,
        'serial_mode' => $serialMode,
        'serial_prefix' => $serialPrefix,
        'manufacturer_serials' => $manufacturerSerials,
    ];
}

function assetTagCurrentStock(PDO $pdo, int $productId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('in','opening') THEN ABS(quantity) WHEN transaction_type='out' THEN -ABS(quantity) WHEN transaction_type='adjustment' THEN quantity ELSE 0 END),0) FROM inventory_transactions WHERE product_id=?");
    $stmt->execute([$productId]);
    return max(0, (int)($stmt->fetchColumn() ?: 0));
}

function assetTagAvailableUnregisteredCapacity(PDO $pdo, int $productId): int
{
    $available = assetTagCurrentStock($pdo, $productId);
    $assets = $pdo->prepare("SELECT COUNT(*) FROM inventory_assets ia LEFT JOIN asset_tag_jobs tag_job ON tag_job.id=ia.asset_tag_job_id
        WHERE ia.product_id=? AND ia.status NOT IN ('assigned','disposed')
        AND (ia.status<>'reserved' OR COALESCE(tag_job.registration_type,'')<>'reconciliation')
        AND NOT EXISTS(SELECT 1 FROM assignments a WHERE a.asset_id=ia.id AND a.status='assigned' AND a.returned_at IS NULL)
        AND NOT EXISTS(SELECT 1 FROM active_hardware_assignments aha WHERE aha.asset_id=ia.id)");
    $assets->execute([$productId]);
    $pending = $pdo->prepare("SELECT COALESCE(SUM(quantity-next_offset),0) FROM asset_tag_jobs
        WHERE product_id=? AND registration_type='available' AND status IN ('pending','processing','failed')");
    $pending->execute([$productId]);
    return max(0, $available - (int)$assets->fetchColumn() - (int)$pending->fetchColumn());
}

function assetTagAvailableStockFloor(PDO $pdo, int $productId): int
{
    return max(0, assetTagCurrentStock($pdo,$productId)-assetTagAvailableUnregisteredCapacity($pdo,$productId));
}

function assetTagStockChangeWithinReservationFloor(int $delta, int $newStock, int $reservationFloor): bool
{
    return $delta >= 0 || $newStock >= max(0, $reservationFloor);
}

/** Block only downward stock changes that would put registered or queued assets above stock. */
function assertAssetTagStockChangeAllowed(PDO $pdo, int $productId, int $delta, int $newStock): void
{
    if ($delta >= 0) return;
    $floor = assetTagAvailableStockFloor($pdo, $productId);
    if (!assetTagStockChangeWithinReservationFloor($delta, $newStock, $floor)) {
        throw new DomainException('This stock reduction would remove units reserved by existing or queued asset tags. Retire or reconcile those assets first.');
    }
}

function assetTagReconciliationCapacity(PDO $pdo, int $productId): int
{
    $assignments = $pdo->prepare("SELECT COALESCE(SUM(a.quantity-a.returned_quantity),0) FROM assignments a WHERE a.product_id=?
        AND a.asset_id IS NULL AND a.status='assigned' AND a.returned_at IS NULL
        AND EXISTS(SELECT 1 FROM employees e WHERE e.id=a.employee_record_id OR e.employee_id=a.employee_id)");
    $assignments->execute([$productId]);
    $reserved = $pdo->prepare("SELECT COUNT(*) FROM inventory_assets ia JOIN asset_tag_jobs j ON j.id=ia.asset_tag_job_id
        WHERE ia.product_id=? AND ia.status='reserved' AND j.registration_type='reconciliation'");
    $reserved->execute([$productId]);
    $pending = $pdo->prepare("SELECT COALESCE(SUM(quantity-next_offset),0) FROM asset_tag_jobs
        WHERE product_id=? AND registration_type='reconciliation' AND status IN ('pending','processing','failed')");
    $pending->execute([$productId]);
    return max(0, (int)$assignments->fetchColumn() - (int)$reserved->fetchColumn() - (int)$pending->fetchColumn());
}

function assetTagMaxExistingNumber(PDO $pdo, string $prefix): int
{
    $generated=$pdo->prepare('SELECT COALESCE(MAX(numeric_value),0) FROM asset_tag_registry WHERE prefix=?');
    $generated->execute([$prefix]);
    $maximum=(string)$generated->fetchColumn();
    $escaped=strtr($prefix,['!'=>'!!','%'=>'!%','_'=>'!_']);
    $legacy=$pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(asset_tag,CHAR_LENGTH(?)+2) AS DECIMAL(20,0))),0)
        FROM asset_tag_registry WHERE prefix IS NULL AND asset_tag LIKE ? ESCAPE '!'
        AND LOWER(asset_tag) REGEXP CONCAT('^',LOWER(?),'-[0-9]+$')");
    $legacy->execute([$prefix,$escaped.'-%',$prefix]);
    $legacyMaximum=(string)$legacy->fetchColumn();
    $maximum=ltrim($maximum,'0') ?: '0';$legacyMaximum=ltrim($legacyMaximum,'0') ?: '0';
    $maximumString=(strlen($legacyMaximum)>strlen($maximum)||(strlen($legacyMaximum)===strlen($maximum)&&strcmp($legacyMaximum,$maximum)>0))?$legacyMaximum:$maximum;
    if (strlen($maximumString)>strlen((string)PHP_INT_MAX)||(strlen($maximumString)===strlen((string)PHP_INT_MAX)&&strcmp($maximumString,(string)PHP_INT_MAX)>0)) {
        throw new DomainException('No numeric range is available for this prefix.');
    }
    return (int)$maximumString;
}

function assetTagRangeHasRegisteredNumber(PDO $pdo, string $prefix, int $first, int $last): bool
{
    $generated=$pdo->prepare('SELECT 1 FROM asset_tag_registry WHERE prefix=? AND numeric_value BETWEEN ? AND ? LIMIT 1 FOR UPDATE');
    $generated->execute([$prefix,$first,$last]);
    if ($generated->fetchColumn()) return true;
    $escaped=strtr($prefix,['!'=>'!!','%'=>'!%','_'=>'!_']);
    $legacy=$pdo->prepare("SELECT 1 FROM asset_tag_registry WHERE prefix IS NULL AND asset_tag LIKE ? ESCAPE '!'
        AND LOWER(asset_tag) REGEXP CONCAT('^',LOWER(?),'-[0-9]+$')
        AND CAST(SUBSTRING(asset_tag,CHAR_LENGTH(?)+2) AS DECIMAL(20,0)) BETWEEN ? AND ?
        LIMIT 1 FOR UPDATE");
    $legacy->execute([$escaped.'-%',$prefix,$prefix,$first,$last]);
    return (bool)$legacy->fetchColumn();
}

function suggestedAssetTagStart(PDO $pdo, string $prefix): int
{
    $prefix = normalizeAssetTagPrefix($prefix);
    $range = $pdo->prepare('SELECT COALESCE(MAX(last_number),0)+1 FROM asset_tag_ranges WHERE prefix=?');
    $range->execute([$prefix]);
    $suggestion = max(1, (int)$range->fetchColumn());
    $maximum=assetTagMaxExistingNumber($pdo,$prefix);
    if ($maximum >= PHP_INT_MAX) throw new DomainException('No numeric range is available for this prefix.');
    $suggestion=max($suggestion,$maximum+1);
    return $suggestion;
}

function assetTagOverlap(PDO $pdo, string $prefix, int $first, int $last): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM asset_tag_ranges WHERE prefix=? AND first_number<=? AND last_number>=? LIMIT 1 FOR UPDATE');
    $stmt->execute([$prefix, $last, $first]);
    return (bool)$stmt->fetchColumn();
}

/** Reserve a prefix range and a product stock allocation in one transaction. */
function createAssetTagJob(PDO $pdo, array $input, string $requestKey, int $actorId): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $requestKey)) throw new DomainException('Refresh the page and try again.');
    $data = validateAssetTagBulkInput($input);
    $hash = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare('SELECT id,request_hash,status,starting_number,quantity,prefix,serial_mode,serial_prefix,internal_serial_first_number FROM asset_tag_jobs WHERE request_key=?');
        $existing->execute([$requestKey]);
        $old = $existing->fetch();
        if ($old) {
            if (!hash_equals((string)$old['request_hash'], $hash)) throw new DomainException('This request key was already used with different settings. Refresh the page to start a new request.');
            $pdo->commit();
            return $old + ['reused' => true];
        }

        $product = $pdo->prepare('SELECT id,name,serialized FROM products WHERE id=? AND active=1 FOR UPDATE');
        $product->execute([$data['product_id']]);
        $productRow = $product->fetch();
        if (!$productRow || !(bool)$productRow['serialized']) throw new DomainException('Bulk asset tags can only be generated for an active serialized product.');
        $existing->execute([$requestKey]);
        $old = $existing->fetch();
        if ($old) {
            if (!hash_equals((string)$old['request_hash'], $hash)) throw new DomainException('This request key was already used with different settings. Refresh the page to start a new request.');
            $pdo->commit();
            return $old + ['reused' => true];
        }
        $capacity = $data['registration_type'] === 'available'
            ? assetTagAvailableUnregisteredCapacity($pdo, $data['product_id'])
            : assetTagReconciliationCapacity($pdo, $data['product_id']);
        if ($data['quantity'] > $capacity) {
            throw new DomainException('Only ' . $capacity . ' unregistered ' . ($data['registration_type'] === 'available' ? 'available' : 'assigned') . ' unit(s) remain for this product.');
        }

        $pdo->prepare('INSERT INTO asset_tag_prefix_sequences(prefix,next_number) VALUES(?,1) ON DUPLICATE KEY UPDATE prefix=VALUES(prefix)')->execute([$data['prefix']]);
        $sequence = $pdo->prepare('SELECT next_number FROM asset_tag_prefix_sequences WHERE prefix=? FOR UPDATE');
        $sequence->execute([$data['prefix']]);
        $next = (int)$sequence->fetchColumn();
        $requested = $data['starting_number'];
        $existingMaximum=assetTagMaxExistingNumber($pdo,$data['prefix']);
        if ($existingMaximum >= PHP_INT_MAX) throw new DomainException('No numeric range is available for this prefix.');
        $first = $requested ?? max($next,$existingMaximum+1);
        $last = $first + $data['quantity'] - 1;
        if ($requested === null) {
            while (assetTagOverlap($pdo, $data['prefix'], $first, $last) || assetTagRangeHasRegisteredNumber($pdo,$data['prefix'],$first,$last)) {
                $first++;
                $last++;
                if ($last > PHP_INT_MAX - 1) throw new DomainException('No numeric range is available for this prefix.');
            }
        } elseif (assetTagOverlap($pdo, $data['prefix'], $first, $last) || assetTagRangeHasRegisteredNumber($pdo,$data['prefix'],$first,$last)) {
            throw new DomainException('That numeric range overlaps an existing or previously reserved asset tag range.');
        }
        buildAssetTag($data['prefix'], $first, $data['padding_digits']);
        buildAssetTag($data['prefix'], $last, $data['padding_digits']);
        $internalSerialFirst = $data['serial_mode'] === 'automatic'
            ? reserveInternalSerialRange($pdo,(string)$data['serial_prefix'],$data['quantity'])
            : null;

        $insert = $pdo->prepare("INSERT INTO asset_tag_jobs
            (request_key,request_hash,product_id,prefix,starting_number,quantity,padding_digits,asset_location,asset_condition,registration_type,serial_mode,serial_prefix,internal_serial_first_number,status,batch_size,created_by)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,?)");
        $insert->execute([$requestKey,$hash,$data['product_id'],$data['prefix'],$first,$data['quantity'],$data['padding_digits'],$data['asset_location'],$data['asset_condition'],$data['registration_type'],$data['serial_mode'],$data['serial_prefix'],$internalSerialFirst,assetTagBatchSize(),$actorId]);
        $jobId = (int)$pdo->lastInsertId();
        if ($data['serial_mode'] === 'manual') {
            $reserveSerial = $pdo->prepare('INSERT INTO manufacturer_serial_registry(serial_number,job_id,job_offset) VALUES(?,?,?)');
            foreach ($data['manufacturer_serials'] as $offset=>$serial) $reserveSerial->execute([$serial,$jobId,$offset]);
        }
        $range = $pdo->prepare('INSERT INTO asset_tag_ranges(job_id,prefix,first_number,last_number) VALUES(?,?,?,?)');
        $range->execute([$jobId,$data['prefix'],$first,$last]);
        $pdo->prepare('UPDATE asset_tag_prefix_sequences SET next_number=GREATEST(next_number,?) WHERE prefix=?')->execute([$last + 1,$data['prefix']]);
        $jobAudit=$pdo->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details,ip_address,source_system,source_id) VALUES(?,?,?,?,?,?,\'asset_tag_job\',?)');
        $jobAudit->execute([$actorId,'asset_tag_job_created','asset_tag_job',$jobId,$data['prefix'] . ' range ' . $first . '–' . $last . '; quantity ' . $data['quantity'] . '; stock mode ' . $data['registration_type'] . '; serial mode ' . $data['serial_mode'],trustedRequestIpAddress(),$jobId]);
        $pdo->commit();
        return ['id'=>$jobId,'request_hash'=>$hash,'status'=>'pending','starting_number'=>$first,'quantity'=>$data['quantity'],'prefix'=>$data['prefix'],'serial_mode'=>$data['serial_mode'],'serial_prefix'=>$data['serial_prefix'],'internal_serial_first_number'=>$internalSerialFirst,'reused'=>false];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($exception instanceof PDOException) {
            $retryLookup = $pdo->prepare('SELECT id,request_hash,status,starting_number,quantity,prefix,serial_mode,serial_prefix,internal_serial_first_number FROM asset_tag_jobs WHERE request_key=?');
            $retryLookup->execute([$requestKey]);
            $raced = $retryLookup->fetch();
            if ($raced && hash_equals((string)$raced['request_hash'],$hash)) return $raced + ['reused'=>true];
        }
        throw $exception;
    }
}

function assetTagRangeHasExistingNumber(array $numbers, int $first, int $last): bool
{
    foreach ($numbers as $number => $_) if ($number >= $first && $number <= $last) return true;
    return false;
}

function registerSingleAsset(PDO $pdo, int $productId, string $assetTag, ?string $serial, ?string $location, ?string $condition, ?string $notes, string $serialMode = 'fill_later', ?string $serialPrefix = null): int
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $assetTag = trim($assetTag);
        $serial = trim((string)$serial);
        if ($assetTag === '' || strlen($assetTag) > 100 || strlen((string)$serial) > 150 || strlen((string)$location) > 150 || strlen((string)$notes) > 4000) {
            throw new DomainException('Enter a valid asset tag, serial number, location, and notes.');
        }
        if (!in_array($serialMode,['manual','automatic','fill_later'],true)) throw new DomainException('Choose Manual, Automatic, or Fill Later serial mode.');
        if ($serialMode === 'manual' && $serial === '') throw new DomainException('Enter the manufacturer serial number for Manual mode.');
        $p = $pdo->prepare('SELECT id,serialized FROM products WHERE id=? AND active=1 FOR UPDATE');
        $p->execute([$productId]);
        $product = $p->fetch();
        if (!$product || !(bool)$product['serialized']) throw new DomainException('Choose an active serialized product.');
        if (assetTagAvailableUnregisteredCapacity($pdo, $productId) < 1) throw new DomainException('No unregistered available stock remains for this product.');
        $registryPrefix = null;$registryNumber = null;
        if (preg_match('/^(.+)-([0-9]+)$/D',$assetTag,$match)) {
            try { $parsedPrefix=normalizeAssetTagPrefix($match[1]); } catch (DomainException) { $parsedPrefix=''; }
            $normalizedNumber=ltrim($match[2],'0');
            $parsedNumber=filter_var($normalizedNumber===''?'0':$normalizedNumber,FILTER_VALIDATE_INT);
            if ($parsedPrefix!=='' && $parsedNumber!==false && $parsedNumber>0) {
                $pdo->prepare('INSERT INTO asset_tag_prefix_sequences(prefix,next_number) VALUES(?,1) ON DUPLICATE KEY UPDATE prefix=VALUES(prefix)')->execute([$parsedPrefix]);
                $sequence=$pdo->prepare('SELECT next_number FROM asset_tag_prefix_sequences WHERE prefix=? FOR UPDATE');$sequence->execute([$parsedPrefix]);$sequence->fetchColumn();
                if (assetTagOverlap($pdo,$parsedPrefix,(int)$parsedNumber,(int)$parsedNumber)) throw new DomainException('This numeric tag is already reserved by a bulk asset tag range.');
                $registryPrefix=$parsedPrefix;$registryNumber=(int)$parsedNumber;
                if ($registryNumber < PHP_INT_MAX) $pdo->prepare('UPDATE asset_tag_prefix_sequences SET next_number=GREATEST(next_number,?) WHERE prefix=?')->execute([$registryNumber+1,$parsedPrefix]);
            }
        }
        $registry = $pdo->prepare('INSERT INTO asset_tag_registry(asset_tag,prefix,numeric_value) VALUES(?,?,?)');
        $registry->execute([$assetTag,$registryPrefix,$registryNumber]);
        $internalSerial = $serialMode === 'automatic'
            ? buildInternalSerialNumber((string)($serialPrefix ?: 'SN'),reserveInternalSerialRange($pdo,(string)($serialPrefix ?: 'SN'),1))
            : null;
        $costReference = function_exists('takeInventoryAssetCostReference') ? takeInventoryAssetCostReference($pdo,$productId) : null;
        $insert = $pdo->prepare("INSERT INTO inventory_assets(product_id,asset_tag,serial_number,internal_serial_number,acquisition_unit_cost,acquisition_cost_layer_id,status,location,asset_condition,notes) VALUES(?,?,?,?,?,?, 'available',?,?,?)");
        $insert->execute([$productId,$assetTag,$serialMode==='manual'?$serial:null,$internalSerial,$costReference['unit_cost']??null,$costReference['cost_layer_id']??null,$location ?: null,$condition,$notes ?: null]);
        $assetId=(int)$pdo->lastInsertId();
        if ($serialMode === 'manual') $pdo->prepare('INSERT INTO manufacturer_serial_registry(serial_number,asset_id) VALUES(?,?)')->execute([$serial,$assetId]);
        if ($ownsTransaction) $pdo->commit();
        return $assetId;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function updateAssetManufacturerSerial(PDO $pdo, int $assetId, string $serial, int $actorId): ?string
{
    $serial = trim($serial);
    if (strlen($serial) > 150) throw new DomainException('Manufacturer serial numbers must be 150 characters or fewer.');
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT id,asset_tag,serial_number FROM inventory_assets WHERE id=? FOR UPDATE');
        $query->execute([$assetId]);
        $asset = $query->fetch();
        if (!$asset) throw new DomainException('The registered hardware asset could not be found.');
        $newSerial = $serial !== '' ? $serial : null;
        if ($newSerial !== $asset['serial_number']) {
            $pdo->prepare('UPDATE inventory_assets SET serial_number=? WHERE id=?')->execute([$newSerial,$assetId]);
            $pdo->prepare('DELETE FROM manufacturer_serial_registry WHERE asset_id=?')->execute([$assetId]);
            if ($newSerial !== null) $pdo->prepare('INSERT INTO manufacturer_serial_registry(serial_number,asset_id) VALUES(?,?)')->execute([$newSerial,$assetId]);
            audit('hardware_manufacturer_serial_updated','asset',$assetId,$asset['asset_tag'] . ' · ' . ($newSerial ?? 'serial cleared'));
        }
        $pdo->commit();
        return $newSerial;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function suggestedInternalSerialNumber(PDO $pdo, string $prefix): string
{
    $prefix = normalizeAssetTagPrefix($prefix);
    $stmt = $pdo->prepare('SELECT next_number FROM internal_serial_prefix_sequences WHERE prefix=?');
    $stmt->execute([$prefix]);
    return buildInternalSerialNumber($prefix,(int)($stmt->fetchColumn() ?: 1));
}

/** Process exactly one committed batch. Safe to call concurrently from many workers. */
function processNextAssetTagBatch(PDO $pdo, ?int $batchSize = null, ?int $onlyJobId = null): ?array
{
    $pdo->beginTransaction();
    $job = null;
    try {
        $sql="SELECT * FROM asset_tag_jobs WHERE status IN ('pending','processing')" . ($onlyJobId ? ' AND id=?' : '') . ' ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED';
        $query=$pdo->prepare($sql);
        $query->execute($onlyJobId ? [$onlyJobId] : []);
        $job = $query->fetch();
        if (!$job) { $pdo->commit(); return null; }
        $jobId = (int)$job['id'];
        $productQuery=$pdo->prepare('SELECT serialized,active FROM products WHERE id=? FOR UPDATE');
        $productQuery->execute([(int)$job['product_id']]);
        $product=$productQuery->fetch();
        if (!$product || !(bool)$product['serialized'] || !(bool)$product['active']) throw new DomainException('The product must remain active and serialized while its tag job is pending.');
        $offset = (int)$job['next_offset'];
        $take = min(max(1, $batchSize ?? assetTagBatchSize()), (int)$job['batch_size'], (int)$job['quantity'] - $offset);
        $firstNumber = (int)$job['starting_number'] + $offset;
        $status = $job['registration_type'] === 'reconciliation' ? 'reserved' : 'available';
        $registry = $pdo->prepare('INSERT INTO asset_tag_registry(asset_tag,prefix,numeric_value,request_key) VALUES(?,?,?,?)');
        $assets = $pdo->prepare('INSERT INTO inventory_assets(product_id,asset_tag,serial_number,internal_serial_number,acquisition_unit_cost,acquisition_cost_layer_id,status,location,asset_condition,asset_tag_job_id) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $manualSerialQuery = $pdo->prepare('SELECT serial_number FROM manufacturer_serial_registry WHERE job_id=? AND job_offset=? FOR UPDATE');
        $manualSerialLink = $pdo->prepare('UPDATE manufacturer_serial_registry SET asset_id=? WHERE job_id=? AND job_offset=?');
        for ($i=0; $i<$take; $i++) {
            $number = $firstNumber + $i;
            $tag = buildAssetTag((string)$job['prefix'], $number, (int)$job['padding_digits']);
            $registry->execute([$tag,$job['prefix'],$number,$job['request_key']]);
            $manufacturerSerial = null;
            if ($job['serial_mode'] === 'manual') {
                $manualSerialQuery->execute([$jobId,$offset+$i]);
                $manufacturerSerial = $manualSerialQuery->fetchColumn();
                if ($manufacturerSerial === false) throw new DomainException('A reserved manufacturer serial number is missing from this bulk job.');
            }
            $internalSerial = $job['serial_mode'] === 'automatic'
                ? buildInternalSerialNumber((string)$job['serial_prefix'],(int)$job['internal_serial_first_number']+$offset+$i)
                : null;
            $costReference = $job['registration_type'] === 'reconciliation' || !function_exists('takeInventoryAssetCostReference') ? null : takeInventoryAssetCostReference($pdo,(int)$job['product_id']);
            $assets->execute([(int)$job['product_id'],$tag,$manufacturerSerial?:null,$internalSerial,$costReference['unit_cost']??null,$costReference['cost_layer_id']??null,$status,$job['asset_location'],$job['asset_condition'],$jobId]);
            if ($job['serial_mode'] === 'manual') $manualSerialLink->execute([(int)$pdo->lastInsertId(),$jobId,$offset+$i]);
        }
        $newOffset = $offset + $take;
        $newStatus = $newOffset >= (int)$job['quantity'] ? 'completed' : 'processing';
        $update = $pdo->prepare('UPDATE asset_tag_jobs SET next_offset=?,status=?,last_error=NULL,completed_at=IF(?="completed",NOW(),NULL) WHERE id=?');
        $update->execute([$newOffset,$newStatus,$newStatus,$jobId]);
        $audit = $pdo->prepare("INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details,source_system,source_id) VALUES(?,?,?,?,?,'asset_tag_worker',?)");
        $auditDetails="Generated {$take} tags for {$job['prefix']} batch starting {$firstNumber}; serial mode {$job['serial_mode']}";
        if($job['serial_mode']==='automatic')$auditDetails.='; internal serial range '.buildInternalSerialNumber((string)$job['serial_prefix'],(int)$job['internal_serial_first_number']+$offset).' to '.buildInternalSerialNumber((string)$job['serial_prefix'],(int)$job['internal_serial_first_number']+$newOffset-1);
        $audit->execute([(int)$job['created_by'],'bulk_asset_tags_generated','asset_tag_job',$jobId,$auditDetails,$jobId]);
        $pdo->commit();
        return ['id'=>$jobId,'created'=>$take,'completed'=>$newStatus==='completed','offset'=>$newOffset,'quantity'=>(int)$job['quantity']];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($job && isset($job['id'])) {
            try {
                $failure = $pdo->prepare("UPDATE asset_tag_jobs SET status=IF(attempts>=4,'failed','pending'),attempts=attempts+1,last_error=? WHERE id=?");
                $failure->execute([substr($exception->getMessage(),0,1000), (int)$job['id']]);
            } catch (Throwable $ignored) { error_log('Unable to record asset tag worker failure: ' . $ignored->getMessage()); }
        }
        error_log('Asset tag batch failed: ' . $exception->getMessage());
        return ['id'=>(int)($job['id'] ?? 0),'error'=>'Batch failed; safe retry is available.'];
    }
}

function retryAssetTagJob(PDO $pdo, int $jobId): bool
{
    $stmt = $pdo->prepare("UPDATE asset_tag_jobs SET status='pending',attempts=0,last_error=NULL WHERE id=? AND status='failed'");
    $stmt->execute([$jobId]);
    return $stmt->rowCount() === 1;
}

/** Attach one explicitly selected generated tag to one existing bulk assignment. */
function reconcileAssetTagAssignment(PDO $pdo, int $assignmentId, int $assetId, int $actorId): array
{
    $pdo->beginTransaction();
    try {
        $assignmentQuery = $pdo->prepare("SELECT a.*,e.id employee_pk,e.employee_id current_employee_id,e.full_name current_name,e.department current_department,e.location current_location
            FROM assignments a LEFT JOIN employees e ON e.id=a.employee_record_id
            WHERE a.id=? FOR UPDATE");
        $assignmentQuery->execute([$assignmentId]);
        $assignment = $assignmentQuery->fetch();
        if (!$assignment || $assignment['asset_id'] !== null || $assignment['status'] !== 'assigned' || $assignment['returned_at'] !== null || (int)$assignment['quantity'] < 1) {
            throw new DomainException('This assignment is no longer eligible for tag reconciliation.');
        }
        if (!$assignment['employee_pk'] && $assignment['employee_id']) {
            $employeeQuery = $pdo->prepare('SELECT id,employee_id,full_name,department,location FROM employees WHERE employee_id=? FOR UPDATE');
            $employeeQuery->execute([$assignment['employee_id']]);
            $employee = $employeeQuery->fetch();
            if ($employee) {
                $assignment['employee_pk']=$employee['id']; $assignment['current_employee_id']=$employee['employee_id'];
                $assignment['current_name']=$employee['full_name']; $assignment['current_department']=$employee['department']; $assignment['current_location']=$employee['location'];
            }
        }
        if (!$assignment['employee_pk']) throw new DomainException('An active employee record is required before reconciliation.');
        $assetQuery = $pdo->prepare("SELECT ia.id,ia.product_id,ia.asset_tag,ia.status,ia.location,ia.asset_condition,j.registration_type
            FROM inventory_assets ia JOIN asset_tag_jobs j ON j.id=ia.asset_tag_job_id WHERE ia.id=? FOR UPDATE");
        $assetQuery->execute([$assetId]);
        $asset = $assetQuery->fetch();
        if (!$asset || $asset['status'] !== 'reserved' || $asset['registration_type'] !== 'reconciliation' || (int)$asset['product_id'] !== (int)$assignment['product_id']) {
            throw new DomainException('Select a reserved tag from the same product.');
        }
        $personName = (string)($assignment['current_name'] ?: $assignment['assigned_to']);
        $employeeCode = (string)($assignment['current_employee_id'] ?: $assignment['employee_id']);
        $department = $assignment['current_department'] ?? $assignment['department'];
        $location = $assignment['current_location'] ?? $assignment['location'];
        $condition = $asset['asset_condition'] ?: $assignment['condition_at_assignment'];
        $costAllocationQuery=$pdo->prepare("SELECT id,cost_layer_id,source_transaction_id,quantity,returned_quantity,unit_cost FROM inventory_cost_allocations WHERE assignment_id=? AND source_type='assignment' AND quantity>returned_quantity ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $costAllocationQuery->execute([(int)$assignment['id']]);
        $costAllocation=$costAllocationQuery->fetch() ?: null;
        $assetCost=$costAllocation['unit_cost'] ?? null;
        $assetCostLayerId=$costAllocation ? (int)$costAllocation['cost_layer_id'] : null;
        $assetCostTransactionId=isset($costAllocation['source_transaction_id'])?(int)$costAllocation['source_transaction_id']:null;
        if ((int)$assignment['quantity'] === 1) {
            $newAssignmentId = (int)$assignment['id'];
            $update = $pdo->prepare('UPDATE assignments SET asset_id=?,assigned_to=?,employee_id=?,employee_record_id=?,department=?,location=?,condition_at_assignment=? WHERE id=? AND asset_id IS NULL');
            $update->execute([$assetId,$personName,$employeeCode,$assignment['employee_pk'],$department,$location,$condition,$newAssignmentId]);
        } else {
            $pdo->prepare("UPDATE assignments SET status=IF(returned_quantity>=quantity-1,'returned',status),returned_at=IF(returned_quantity>=quantity-1,NOW(),returned_at),quantity=quantity-1 WHERE id=? AND quantity>1")->execute([(int)$assignment['id']]);
            $insert = $pdo->prepare("INSERT INTO assignments(product_id,asset_id,quantity,assigned_to,employee_id,employee_record_id,department,location,assigned_by,status,condition_at_assignment,notes)
                VALUES(?,?,1,?,?,?,?,?,?, 'assigned',?,?)");
            $notes = trim((string)($assignment['notes'] ?? ''));
            $notes .= ($notes !== '' ? "\n" : '') . 'Tag reconciled from assignment #' . (int)$assignment['id'];
            $insert->execute([(int)$assignment['product_id'],$assetId,$personName,$employeeCode,(int)$assignment['employee_pk'],$department,$location,$actorId,$condition,$notes]);
            $newAssignmentId = (int)$pdo->lastInsertId();
            if ($costAllocation) {
                $remainingAllocated=(int)$costAllocation['quantity']-(int)$costAllocation['returned_quantity'];
                if ($remainingAllocated <= 1 && (int)$costAllocation['returned_quantity']===0) {
                    $pdo->prepare('DELETE FROM inventory_cost_allocations WHERE id=?')->execute([(int)$costAllocation['id']]);
                } else {
                    $pdo->prepare('UPDATE inventory_cost_allocations SET quantity=quantity-1 WHERE id=?')->execute([(int)$costAllocation['id']]);
                }
                $pdo->prepare("INSERT INTO inventory_cost_allocations(cost_layer_id,assignment_id,source_transaction_id,source_type,quantity,unit_cost) VALUES(?,?,?,'assignment',1,?)")
                    ->execute([$assetCostLayerId,$newAssignmentId,$assetCostTransactionId,$assetCost]);
            }
        }
        $pdo->prepare("UPDATE inventory_assets SET status='assigned',location=?,asset_condition=COALESCE(asset_condition,?),acquisition_unit_cost=COALESCE(acquisition_unit_cost,?),acquisition_cost_layer_id=COALESCE(acquisition_cost_layer_id,?) WHERE id=? AND status='reserved'")->execute([$location,$condition,$assetCost,$assetCostLayerId,$assetId]);
        $pdo->prepare('INSERT INTO active_hardware_assignments(asset_id,assignment_id) VALUES(?,?)')->execute([$assetId,$newAssignmentId]);
        $history = $pdo->prepare("INSERT INTO hardware_assignment_history(employee_id,assignment_id,action,reason,new_asset_id,performed_by,transaction_id,event_quantity,cost_covered_quantity,acquisition_value) VALUES(?,?,'tagged',?,?,?,?,1,?,?)");
        $history->execute([(int)$assignment['employee_pk'],$newAssignmentId,'Asset tag reconciliation from existing assignment #' . (int)$assignment['id'],$assetId,$actorId,$assetCostTransactionId,$assetCost===null?0:1,$assetCost]);
        audit('asset_tag_reconciled','assignment',$newAssignmentId,$asset['asset_tag'] . ' explicitly associated with ' . $personName . ' (' . $employeeCode . ')');
        $pdo->commit();
        return ['assignment_id'=>$newAssignmentId,'asset_tag'=>$asset['asset_tag'],'employee'=>$personName,'employee_id'=>$employeeCode];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
