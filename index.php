<?php
session_start();
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// ─────────────────────────────────────────────
// Configuration
// ─────────────────────────────────────────────
$WEBHOOKS = [
    'https://hook.eu2.make.com/arn57wp13n4j95eg3yy8anqxah4uashm',
    'https://hook.eu2.make.com/ia9p1f6t7rjkjypayn4jynhi584qt781',
];

$REQUIRED_COLUMNS = [
    'Location'     => ['location', 'site', 'site name', 'customer', 'customer name', 'name'],
    'Full Address' => ['full address', 'address', 'street', 'street address', 'site address'],
    'Postcode'     => ['postcode', 'post code', 'postal code', 'zip', 'zip code', 'zipcode', 'pcode'],
    'Priority'     => ['priority', 'prio', 'urgency', 'priority level'],
    'Tanks'        => ['tanks', 'tank', 'tank id', 'tank no', 'tank number', 'tank ref', 'tank reference', 'device', 'device id'],
];

$TECHNICIANS = [
    'Alex Norfolk'    => ['BN','BR','CR','CT','DA','EC','EN','HA','KT','ME','NW','RH','RM','SE','SL','SM','SW','TN','TW','UB','WC','WD','E1','E2','E3','E4','E5','E6','E7','E8','E9','N1','N2','N3','N4','N5','N6','N7','N8','N9','W1','W2','W3','W4','W5','W6','W7','W8','W9'],
    'Harvey Penney'   => ['BA','BH','BS','DT','EX','GL','GU','PL','PO','RG','SN','SO','SP','TA','TQ','TR'],
    'Josh Lowe'       => ['AL','CB','CM','CO','HP','IG','IP','LU','MK','NN','NR','OX','PE','SG','SS'],
    'Marcus Sloane'   => ['AB','DD','DG','EH','FK','G1','G2','G3','G4','G5','HS','IV','KA','KW','KY','ML','PA','PH','TD','ZE'],
    'Max'             => ['CF','CH','CW','DY','HR','LD','LL','NP','SA','ST','SY','TF','WA','WN','WR','WV'],
    'Michael Barnes'  => ['DH','DL','DN','HG','HU','LS','WF','YO'],
    'Phil Mawdesley'  => ['BB','BD','BL','CA','FY','HD','HX','LA','OL','PR','SK','L1','L2','L3','L4','L5','L6','L7','L8','L9','M1','M2','M3','M4','M5','M6','M7','M8','M9'],
    'Shaun'           => ['CV','DE','LE','LN','NE','NG','SR','TS','WS','B1','B2','B3','B4','B5','B6','B7','B8','B9','S1','S2','S3','S4','S5','S6','S7','S8','S9'],
];

$DUPE_WINDOW = 7200; // 2 hours

// ─────────────────────────────────────────────
// Helper functions
// ─────────────────────────────────────────────
function sendToWebhook($url, $file) {
    $cfile = new CURLFile($file['tmp_name'], $file['type'], $file['name']);
    $ch    = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['file' => $cfile, 'filename' => $file['name']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    return ['code' => $httpCode, 'error' => $curlErr];
}

function sortByPriority($filePath) {
    try {
        $spreadsheet = IOFactory::load($filePath);
    } catch (\Throwable $e) {
        return $filePath;
    }

    $sheet = $spreadsheet->getActiveSheet();
    $data  = $sheet->toArray(null, true, true, false);

    if (count($data) < 2) {
        $spreadsheet->disconnectWorksheets();
        return $filePath;
    }

    $header = array_shift($data);

    $priorityIdx = null;
    foreach ($header as $idx => $val) {
        if ($val !== null && stripos(trim((string)$val), 'priority') !== false) {
            $priorityIdx = $idx;
            break;
        }
    }

    if ($priorityIdx === null) {
        $spreadsheet->disconnectWorksheets();
        return $filePath;
    }

    $data = array_values(array_filter($data, function ($row) {
        return !empty(array_filter($row, fn($v) => $v !== null && $v !== ''));
    }));

    usort($data, function ($a, $b) use ($priorityIdx) {
        return (int)($b[$priorityIdx] ?? 0) - (int)($a[$priorityIdx] ?? 0);
    });

    $rowNum = 2;
    foreach ($data as $row) {
        foreach ($row as $colIdx => $value) {
            $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
            $sheet->setCellValue($colLetter . $rowNum, $value);
        }
        $rowNum++;
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'dtlpg_') . '.xlsx';
    $writer  = new Xlsx($spreadsheet);
    $writer->save($tmpPath);
    $spreadsheet->disconnectWorksheets();

    return $tmpPath;
}

function analyzeFile($filePath, $technicians, $requiredColumns, $dupeWindow) {
    $result = [
        'valid'           => false,
        'missingColumns'  => [],
        'jobCount'        => 0,
        'priority'        => ['high' => 0, 'medium' => 0, 'low' => 0],
        'highestPriority' => 0,
        'lowestPriority'  => 0,
        'technicians'     => [],
        'unassigned'      => 0,
        'rowErrors'       => [],
        'fileHash'        => '',
        'duplicate'       => false,
        'duplicateTime'   => '',
        'duplicateName'   => '',
    ];

    $result['fileHash'] = md5_file($filePath);

    $hashes = $_SESSION['upload_hashes'] ?? [];
    $hashes = array_filter($hashes, fn($h) => (time() - $h['time']) < $dupeWindow);
    $_SESSION['upload_hashes'] = array_values($hashes);

    foreach ($hashes as $h) {
        if ($h['hash'] === $result['fileHash']) {
            $result['duplicate']     = true;
            $result['duplicateTime'] = date('H:i', $h['time']);
            $result['duplicateName'] = $h['name'];
            break;
        }
    }

    try {
        $spreadsheet = IOFactory::load($filePath);
    } catch (\Throwable $e) {
        $result['missingColumns'] = ['Could not read file: ' . $e->getMessage()];
        return $result;
    }

    $sheet = $spreadsheet->getActiveSheet();
    $data  = $sheet->toArray(null, true, true, false);
    $spreadsheet->disconnectWorksheets();

    if (count($data) < 1) {
        $result['missingColumns'] = ['File appears empty'];
        return $result;
    }

    $header = array_shift($data);
    $rawHeaders = [];
    $headerIdxByName = [];
    foreach ($header as $idx => $val) {
        if ($val !== null) {
            $clean = strtolower(trim((string)$val));
            $headerIdxByName[$clean] = $idx;
            $rawHeaders[] = trim((string)$val);
        }
    }
    $result['foundColumns'] = $rawHeaders;

    $resolvedMap = [];
    $missing = [];
    foreach ($requiredColumns as $canonical => $aliases) {
        $found = false;
        foreach ($aliases as $alias) {
            if (isset($headerIdxByName[$alias])) {
                $resolvedMap[strtolower($canonical)] = $headerIdxByName[$alias];
                $found = true;
                break;
            }
        }
        if (!$found) {
            foreach ($headerIdxByName as $hdr => $idx) {
                foreach ($aliases as $alias) {
                    if (str_contains($hdr, $alias) || str_contains($alias, $hdr)) {
                        $resolvedMap[strtolower($canonical)] = $idx;
                        $found = true;
                        break 2;
                    }
                }
            }
        }
        if (!$found) {
            $missing[] = $canonical;
        }
    }
    $result['missingColumns'] = $missing;
    $result['valid']          = true;

    $headerMap = $resolvedMap;

    $data = array_values(array_filter($data, function ($row) {
        return !empty(array_filter($row, fn($v) => $v !== null && $v !== ''));
    }));

    $result['jobCount'] = count($data);
    if ($result['jobCount'] === 0) {
        return $result;
    }

    $priorityIdx = $headerMap['priority'] ?? null;
    $postcodeIdx = $headerMap['postcode'] ?? null;

    if ($priorityIdx !== null) {
        $priorities = array_map(fn($r) => (int)($r[$priorityIdx] ?? 0), $data);
        $result['highestPriority'] = max($priorities);
        $result['lowestPriority']  = min($priorities);
        foreach ($priorities as $p) {
            if ($p >= 7)     $result['priority']['high']++;
            elseif ($p >= 4) $result['priority']['medium']++;
            else             $result['priority']['low']++;
        }
    }

    $techCounts = array_fill_keys(array_keys($technicians), 0);
    $unassigned = 0;

    if ($postcodeIdx !== null) {
        foreach ($data as $row) {
            $pc      = strtoupper(trim((string)($row[$postcodeIdx] ?? '')));
            $matched = false;
            foreach ($technicians as $techName => $prefixes) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($pc, $prefix)) {
                        $techCounts[$techName]++;
                        $matched = true;
                        break 2;
                    }
                }
            }
            if (!$matched) $unassigned++;
        }
    }

    $result['technicians'] = [];
    foreach ($techCounts as $name => $count) {
        $result['technicians'][] = ['name' => $name, 'count' => $count];
    }
    $result['unassigned'] = $unassigned;

    $checkLabels = [
        'location'     => 'Location',
        'full address' => 'Full Address',
        'postcode'     => 'Postcode',
        'priority'     => 'Priority',
        'tanks'        => 'Tanks',
    ];
    $rowErrors = [];
    foreach ($data as $rowIdx => $row) {
        $excelRow  = $rowIdx + 2;
        $problems  = [];
        foreach ($checkLabels as $key => $label) {
            if (!isset($headerMap[$key])) continue;
            $colIdx = $headerMap[$key];
            $val    = trim((string)($row[$colIdx] ?? ''));
            if ($val === '') {
                $problems[] = $label;
            }
        }
        if ($postcodeIdx !== null) {
            $pc = strtoupper(trim((string)($row[$postcodeIdx] ?? '')));
            if ($pc !== '') {
                $matched = false;
                foreach ($technicians as $prefixes) {
                    foreach ($prefixes as $prefix) {
                        if (str_starts_with($pc, $prefix)) { $matched = true; break 2; }
                    }
                }
                if (!$matched) {
                    $problems[] = 'Unrecognised postcode (' . $pc . ')';
                }
            }
        }
        if (!empty($problems)) {
            $loc = '';
            $locIdx = $headerMap['location'] ?? null;
            if ($locIdx !== null) {
                $loc = trim((string)($row[$locIdx] ?? ''));
            }
            $rowErrors[] = [
                'row'      => $excelRow,
                'location' => $loc,
                'issues'   => $problems,
            ];
        }
    }
    $result['rowErrors'] = $rowErrors;

    return $result;
}

function writeAuditLog($filename, $jobCount, $highest, $lowest, $webhookResults) {
    $successCount = count(array_filter($webhookResults, fn($r) => $r['success']));
    $totalCount   = count($webhookResults);
    $logLine      = date('Y-m-d H:i:s') . " | {$filename} | {$jobCount} jobs | Priority {$highest}-{$lowest} | Webhooks: {$successCount}/{$totalCount} OK\n";
    file_put_contents(__DIR__ . '/uploads.log', $logLine, FILE_APPEND | LOCK_EX);
}

// ─────────────────────────────────────────────
// AJAX preview endpoint
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    header('Content-Type: application/json');

    if (!isset($_FILES['jobfile']) || $_FILES['jobfile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['valid' => false, 'missingColumns' => ['No file received']]);
        exit;
    }

    $file = $_FILES['jobfile'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'])) {
        echo json_encode(['valid' => false, 'missingColumns' => ['Invalid file type — .xlsx or .xls only']]);
        exit;
    }

    $analysis = analyzeFile($file['tmp_name'], $TECHNICIANS, $REQUIRED_COLUMNS, $DUPE_WINDOW);
    echo json_encode($analysis);
    exit;
}

// ─────────────────────────────────────────────
// Main form submission
// ─────────────────────────────────────────────
$status         = '';
$message        = '';
$jobCount       = 0;
$priorityHigh   = 0;
$priorityMed    = 0;
$priorityLow    = 0;
$highestPri     = 0;
$lowestPri      = 0;
$webhookResults = [];
$uploadTime     = '';
$techDistro     = [];
$rowErrors      = [];
$unassignedCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    if (!isset($_FILES['jobfile']) || $_FILES['jobfile']['error'] !== UPLOAD_ERR_OK) {
        $status  = 'error';
        $message = 'No file received or upload error. Please try again.';
    } else {
        $file    = $_FILES['jobfile'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['xlsx', 'xls'];

        if (!in_array($ext, $allowed)) {
            $status  = 'error';
            $message = 'Invalid file type. Please upload an Excel file (.xlsx or .xls).';
        } else {
            $analysis       = analyzeFile($file['tmp_name'], $TECHNICIANS, $REQUIRED_COLUMNS, $DUPE_WINDOW);
            $jobCount       = $analysis['jobCount'];
            $priorityHigh   = $analysis['priority']['high'];
            $priorityMed    = $analysis['priority']['medium'];
            $priorityLow    = $analysis['priority']['low'];
            $highestPri     = $analysis['highestPriority'];
            $lowestPri      = $analysis['lowestPriority'];
            $techDistro     = $analysis['technicians'];
            $rowErrors      = $analysis['rowErrors'];
            $unassignedCount = $analysis['unassigned'];

            $sortedPath = sortByPriority($file['tmp_name']);
            $sendFile   = $file;
            if ($sortedPath !== $file['tmp_name']) {
                $sendFile['tmp_name'] = $sortedPath;
            }

            $errors = [];
            foreach ($WEBHOOKS as $url) {
                $result = sendToWebhook($url, $sendFile);
                $ok     = !$result['error'] && $result['code'] >= 200 && $result['code'] < 300;
                $webhookResults[] = [
                    'url'     => $url,
                    'success' => $ok,
                    'code'    => $result['code'],
                ];
                if (!$ok) {
                    $errors[] = 'HTTP ' . $result['code'] . ' from endpoint ' . (count($webhookResults));
                }
            }

            if ($sortedPath !== $file['tmp_name'] && file_exists($sortedPath)) {
                unlink($sortedPath);
            }

            $uploadTime = date('H:i:s');
            writeAuditLog($file['name'], $jobCount, $highestPri, $lowestPri, $webhookResults);

            $_SESSION['upload_hashes']   = $_SESSION['upload_hashes'] ?? [];
            $_SESSION['upload_hashes'][] = [
                'hash' => $analysis['fileHash'],
                'time' => time(),
                'name' => $file['name'],
            ];

            if (empty($errors)) {
                $status  = 'success';
                $message = "{$jobCount} jobs sorted by priority and distributed to " . count($TECHNICIANS) . " technicians.";
            } else {
                $status  = 'error';
                $message = 'One or more endpoints failed. Details: ' . implode('; ', $errors);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DTLPG — Weekly Job Upload</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:       #bfe4ff;
    --surface:  #ffffff;
    --border:   #7a8fa3;
    --accent:   #1a2b4a;
    --accent2:  #2a4a7a;
    --text:     #1a2b4a;
    --muted:    #3d5468;
    --success:  #16a34a;
    --error:    #dc2626;
    --btn-bg:   #1a2b4a;
    --btn-text: #ffffff;
    --focus:    #0044aa;
    --high:     #c0392b;
    --med:      #d4830a;
    --low:      #27ae60;
  }

  .sr-only {
    position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
    overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0;
  }

  .skip-link {
    position: absolute; top: -100%; left: 50%; transform: translateX(-50%);
    background: var(--accent); color: #ffffff; padding: 10px 20px;
    border-radius: 0 0 6px 6px; font-size: 14px; font-weight: 700;
    z-index: 100; text-decoration: none;
  }
  .skip-link:focus { top: 0; }

  html, body {
    height: 100%; background: var(--bg); color: var(--text);
    font-family: Arial, Helvetica, sans-serif; font-weight: 400; line-height: 1.6;
  }

  :focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; }
  a:focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; border-radius: 2px; }

  @media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
      animation-duration: 0.01ms !important;
      animation-iteration-count: 1 !important;
      transition-duration: 0.01ms !important;
    }
  }

  .page {
    position: relative; z-index: 1; min-height: 100vh;
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; padding: 40px 20px;
  }

  .header { text-align: center; margin-bottom: 36px; animation: fadeUp 0.6s ease both; }
  .logo-img { max-width: 340px; width: 100%; height: auto; margin-bottom: 24px; }
  h1 { font-size: clamp(22px, 4vw, 32px); font-weight: 700; line-height: 1.2; color: var(--accent); margin-bottom: 8px; }
  h1 em { font-style: normal; color: var(--accent2); }
  .subtitle { font-size: 15px; color: var(--muted); font-weight: 400; }

  .card {
    width: 100%; max-width: 580px; background: var(--surface);
    border: 1px solid var(--border); border-radius: 8px; padding: 40px;
    position: relative; animation: fadeUp 0.6s 0.15s ease both;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
  }
  .card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0;
    height: 4px; background: var(--accent); border-radius: 8px 8px 0 0;
  }

  .card-heading {
    font-size: 11px; font-weight: 700; letter-spacing: 2px; color: var(--accent);
    text-transform: uppercase; margin-bottom: 24px; display: flex; align-items: center; gap: 8px;
  }
  .card-heading::after { content: ''; flex: 1; height: 1px; background: var(--border); }

  /* ── DROPZONE ── */
  .dropzone {
    border: 2px dashed var(--border); border-radius: 8px; padding: 40px 24px;
    text-align: center; cursor: pointer; transition: border-color 0.2s, background 0.2s;
    position: relative; margin-bottom: 20px; background: #f8fbff;
  }
  .dropzone:hover, .dropzone.drag-over { border-color: var(--accent); background: #eef5ff; }
  .dropzone:focus-within { border-color: var(--accent); outline: 3px solid var(--focus); outline-offset: 2px; background: #eef5ff; }
  .dropzone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
  .dropzone input[type="file"]:focus-visible { outline: none; }
  .dropzone-label { display: block; cursor: pointer; }
  .drop-icon { font-size: 36px; margin-bottom: 12px; display: block; transition: transform 0.2s; }
  .dropzone:hover .drop-icon { transform: translateY(-4px); }
  .drop-title { font-size: 16px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text); margin-bottom: 6px; display: block; }
  .drop-sub { font-size: 13px; color: var(--muted); display: block; }
  .file-selected { margin-top: 14px; font-size: 13px; color: var(--accent); font-weight: 600; display: none; align-items: center; justify-content: center; gap: 6px; }
  .file-selected.visible { display: flex; }

  /* ── PREVIEW CARD ── */
  #previewArea { display: none; margin-bottom: 20px; }
  #previewArea.visible { display: block; animation: fadeUp 0.3s ease both; }

  .preview-card {
    background: #f8fbff; border: 1px solid var(--border); border-radius: 8px;
    padding: 20px; font-size: 14px;
  }
  .preview-heading {
    font-size: 11px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase;
    color: var(--muted); margin-bottom: 14px;
  }
  .preview-stats {
    display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 16px;
  }
  .preview-stat {
    text-align: center; padding: 10px 6px; border-radius: 6px;
    background: var(--surface); border: 1px solid var(--border);
  }
  .preview-stat-val { font-size: 22px; font-weight: 700; display: block; line-height: 1.2; }
  .preview-stat-label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); }
  .preview-stat.high .preview-stat-val { color: var(--high); }
  .preview-stat.med .preview-stat-val { color: var(--med); }
  .preview-stat.low .preview-stat-val { color: var(--low); }
  .preview-stat.total .preview-stat-val { color: var(--accent); }

  .preview-section-title {
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;
    color: var(--muted); margin: 14px 0 8px; padding-top: 14px; border-top: 1px solid var(--border);
  }

  .preview-tech-list { list-style: none; display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; }
  .preview-tech-list li {
    font-size: 13px; display: flex; justify-content: space-between;
    padding: 3px 0; border-bottom: 1px dotted #d0d8e0;
  }
  .preview-tech-list .tech-name { color: var(--text); }
  .preview-tech-list .tech-count { font-weight: 700; color: var(--accent); }
  .preview-unassigned { font-size: 12px; color: var(--med); margin-top: 6px; }

  .preview-error {
    background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
    padding: 12px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 12px;
  }
  .preview-error strong { display: block; margin-bottom: 4px; }

  .preview-dupe-warn {
    background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
    padding: 12px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 12px;
  }

  .preview-warn-box {
    background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px;
    padding: 16px; margin-bottom: 12px; color: #78350f;
  }
  .preview-warn-box strong { display: block; font-size: 13px; margin-bottom: 8px; color: #92400e; }
  .preview-warn-list { list-style: none; margin: 0 0 12px; padding: 0; }
  .preview-warn-list li {
    font-size: 13px; padding: 6px 0; border-bottom: 1px solid #fde68a;
    display: flex; gap: 8px; line-height: 1.4;
  }
  .preview-warn-list li:last-child { border-bottom: none; }
  .warn-col { font-weight: 700; color: #92400e; white-space: nowrap; min-width: 90px; }
  .warn-consequence { color: #78350f; }
  .preview-warn-footer {
    font-size: 12px; color: #a16207; line-height: 1.5;
    padding-top: 10px; border-top: 1px solid #fde68a; margin-bottom: 10px;
  }
  .btn-ignore {
    display: inline-flex; align-items: center; gap: 6px;
    background: #f59e0b; color: #ffffff; border: none; border-radius: 5px;
    padding: 9px 16px; font-size: 12px; font-weight: 700; letter-spacing: 1px;
    text-transform: uppercase; cursor: pointer; transition: background 0.2s;
    width: 100%;
    justify-content: center;
  }
  .btn-ignore:hover { background: #d97706; }
  .ignored-badge {
    display: inline-block; background: #fbbf24; color: #78350f;
    font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
    padding: 3px 8px; border-radius: 3px; margin-left: 6px; vertical-align: middle;
  }

  .preview-loading {
    text-align: center; padding: 24px; color: var(--muted); font-size: 13px;
  }
  .preview-loading .mini-spin {
    display: inline-block; width: 16px; height: 16px;
    border: 2px solid var(--border); border-top-color: var(--accent);
    border-radius: 50%; animation: spin 0.6s linear infinite;
    vertical-align: middle; margin-right: 8px;
  }

  /* ── BUTTON ── */
  .btn {
    width: 100%; padding: 16px; background: var(--btn-bg); color: var(--btn-text);
    border: none; border-radius: 6px; font-size: 15px; font-weight: 700;
    letter-spacing: 2px; text-transform: uppercase; cursor: pointer;
    transition: background 0.2s, transform 0.1s;
    display: flex; align-items: center; justify-content: center; gap: 10px;
  }
  .btn:hover { background: var(--accent2); }
  .btn:active { transform: scale(0.99); }
  .btn:disabled { background: #c0cdd8; color: #576673; cursor: not-allowed; transform: none; }
  .spinner { width: 18px; height: 18px; border: 2px solid rgba(255,255,255,0.3); border-top-color: #ffffff; border-radius: 50%; animation: spin 0.7s linear infinite; display: none; }
  .btn.loading .spinner { display: block; }
  .btn.loading .btn-text { opacity: 0.7; }

  /* ── CONFIRM OVERLAY ── */
  .confirm-overlay {
    display: none; position: fixed; inset: 0; z-index: 200;
    background: rgba(26,43,74,0.55); backdrop-filter: blur(4px);
    align-items: center; justify-content: center; padding: 20px;
  }
  .confirm-overlay.visible { display: flex; animation: fadeIn 0.2s ease; }
  .confirm-box {
    background: var(--surface); border-radius: 10px; padding: 32px;
    max-width: 420px; width: 100%; box-shadow: 0 12px 40px rgba(0,0,0,0.2);
    text-align: center;
  }
  .confirm-box h3 {
    font-size: 18px; font-weight: 700; color: var(--accent); margin-bottom: 12px;
  }
  .confirm-box p { font-size: 14px; color: var(--muted); margin-bottom: 24px; line-height: 1.6; }
  .confirm-box .job-count-highlight { font-size: 36px; font-weight: 700; color: var(--accent); display: block; margin-bottom: 4px; }
  .confirm-actions { display: flex; gap: 12px; }
  .confirm-actions .btn { flex: 1; padding: 14px; font-size: 13px; letter-spacing: 1.5px; }
  .btn-cancel { background: #e5e7eb !important; color: var(--text) !important; }
  .btn-cancel:hover { background: #d1d5db !important; }

  /* ── ALERTS ── */
  .alert {
    margin-top: 20px; padding: 16px 18px; border-radius: 6px; font-size: 14px;
    display: flex; align-items: flex-start; gap: 12px; animation: fadeUp 0.3s ease both;
  }
  .alert-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
  .alert.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
  .alert.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

  /* ── INFO STRIP ── */
  .info-strip { margin-top: 24px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
  .info-strip.four-col { grid-template-columns: repeat(4, 1fr); }
  .info-item { background: #f8fbff; border: 1px solid var(--border); border-radius: 6px; padding: 12px; text-align: center; }
  .info-item-val { font-size: 20px; font-weight: 700; color: var(--accent); display: block; margin-bottom: 2px; }
  .info-item-val.high { color: var(--high); }
  .info-item-val.med { color: var(--med); }
  .info-item-val.low { color: var(--low); }
  .info-item-label { font-size: 11px; color: var(--muted); letter-spacing: 1px; text-transform: uppercase; }

  /* ── WEBHOOK STATUS ── */
  .webhook-status { margin-top: 16px; }
  .webhook-row {
    display: flex; align-items: center; gap: 10px; padding: 8px 12px;
    border-radius: 6px; font-size: 13px; margin-bottom: 6px;
  }
  .webhook-row.ok { background: #ecfdf5; color: #065f46; }
  .webhook-row.fail { background: #fef2f2; color: #991b1b; }
  .webhook-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .webhook-row.ok .webhook-dot { background: var(--success); }
  .webhook-row.fail .webhook-dot { background: var(--error); }
  .webhook-label { flex: 1; }
  .webhook-code { font-weight: 700; font-size: 12px; }

  .timestamp { margin-top: 16px; font-size: 12px; color: var(--muted); text-align: center; }

  /* ── SUCCESS DETAILS ── */
  .result-section {
    margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border);
  }
  .result-section-title {
    font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase;
    color: var(--muted); margin-bottom: 10px;
  }
  .tech-distro { list-style: none; display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; }
  .tech-distro li {
    font-size: 13px; display: flex; justify-content: space-between; align-items: center;
    padding: 5px 0; border-bottom: 1px dotted #d0d8e0;
  }
  .tech-distro .td-name { color: var(--text); }
  .tech-distro .td-count { font-weight: 700; color: var(--accent); min-width: 28px; text-align: right; }
  .tech-distro .td-zero { color: var(--border); }
  .td-unassigned { font-size: 12px; color: var(--med); margin-top: 6px; }

  .row-errors-panel {
    background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px;
    padding: 14px; margin-top: 12px;
  }
  .row-errors-panel summary {
    font-size: 12px; font-weight: 700; color: #92400e; cursor: pointer;
    letter-spacing: 0.5px;
  }
  .row-errors-panel summary:hover { text-decoration: underline; }
  .row-errors-list { list-style: none; margin-top: 10px; max-height: 260px; overflow-y: auto; }
  .row-errors-list li {
    font-size: 12px; padding: 6px 0; border-bottom: 1px solid #fde68a;
    display: flex; gap: 8px; line-height: 1.4; color: #78350f;
  }
  .row-errors-list li:last-child { border-bottom: none; }
  .re-row { font-weight: 700; color: #92400e; white-space: nowrap; min-width: 52px; }
  .re-loc { color: #78350f; min-width: 100px; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .re-issues { color: #a16207; flex: 1; }

  /* ── FOOTER ── */
  .footer {
    margin-top: 32px; text-align: center; font-size: 13px;
    color: var(--muted); animation: fadeUp 0.6s 0.3s ease both;
  }
  .footer a { color: var(--accent); text-decoration: underline; text-decoration-thickness: 1px; text-underline-offset: 2px; font-weight: 600; }
  .footer a:hover { text-decoration-thickness: 2px; }

  @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
  @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
  @keyframes spin { to { transform: rotate(360deg); } }

  @media (max-width: 560px) {
    .card { padding: 28px 20px; }
    .info-strip, .info-strip.four-col { grid-template-columns: 1fr 1fr; }
    .preview-stats { grid-template-columns: 1fr 1fr; }
    .preview-tech-list { grid-template-columns: 1fr; }
    .tech-distro { grid-template-columns: 1fr; }
    .logo-img { max-width: 260px; }
    .confirm-actions { flex-direction: column; }
  }
</style>
</head>
<body>
<a href="#main-content" class="skip-link">Skip to main content</a>

<div class="page">

  <header class="header" role="banner">
    <img src="https://dtlpg.co.uk/wp-content/uploads/2024/03/cropped-logo-png-2048x512.png" alt="DTLPG — DT Leak Protection Group" class="logo-img">
    <h1>Weekly Job <em>Distribution</em></h1>
    <p class="subtitle">Upload your Excel file — we'll handle the rest automatically</p>
  </header>

  <main id="main-content" class="card">
    <h2 class="card-heading">Upload Weekly Jobs File</h2>

    <?php if ($status === 'success'): ?>
      <div class="alert success" role="status" aria-live="polite">
        <span class="alert-icon" aria-hidden="true">&#10003;</span>
        <div><?= htmlspecialchars($message) ?></div>
      </div>

      <div class="info-strip four-col" style="margin-top:20px;" aria-label="Job summary">
        <div class="info-item">
          <span class="info-item-val"><?= $jobCount ?></span>
          <span class="info-item-label">Total Jobs</span>
        </div>
        <div class="info-item">
          <span class="info-item-val high"><?= $priorityHigh ?></span>
          <span class="info-item-label">High (7–9)</span>
        </div>
        <div class="info-item">
          <span class="info-item-val med"><?= $priorityMed ?></span>
          <span class="info-item-label">Medium (4–6)</span>
        </div>
        <div class="info-item">
          <span class="info-item-val low"><?= $priorityLow ?></span>
          <span class="info-item-label">Low (1–3)</span>
        </div>
      </div>

      <?php if (!empty($techDistro)): ?>
        <div class="result-section" aria-label="Technician distribution">
          <div class="result-section-title">Technician Distribution</div>
          <ul class="tech-distro">
            <?php foreach ($techDistro as $tech): ?>
              <li>
                <span class="td-name"><?= htmlspecialchars($tech['name']) ?></span>
                <span class="td-count <?= $tech['count'] === 0 ? 'td-zero' : '' ?>"><?= $tech['count'] ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php if ($unassignedCount > 0): ?>
            <div class="td-unassigned">&#9888; <?= $unassignedCount ?> job(s) with unrecognised postcodes</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($rowErrors)): ?>
        <div class="result-section">
          <details class="row-errors-panel" open>
            <summary>&#9888; <?= count($rowErrors) ?> row(s) with issues</summary>
            <ul class="row-errors-list">
              <?php foreach ($rowErrors as $re): ?>
                <li>
                  <span class="re-row">Row <?= $re['row'] ?></span>
                  <span class="re-loc" title="<?= htmlspecialchars($re['location']) ?>"><?= htmlspecialchars($re['location'] ?: '—') ?></span>
                  <span class="re-issues"><?= htmlspecialchars(implode(', ', $re['issues'])) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </details>
        </div>
      <?php endif; ?>

      <div class="webhook-status" aria-label="Webhook results">
        <?php foreach ($webhookResults as $i => $wh): ?>
          <div class="webhook-row <?= $wh['success'] ? 'ok' : 'fail' ?>">
            <span class="webhook-dot"></span>
            <span class="webhook-label">Endpoint <?= $i + 1 ?></span>
            <span class="webhook-code"><?= $wh['success'] ? 'OK' : 'HTTP ' . $wh['code'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <p class="timestamp">Processed at <?= htmlspecialchars($uploadTime) ?></p>

      <div style="margin-top:20px; text-align:center;">
        <a href="?" style="font-size:13px; color:var(--muted); text-decoration:underline; text-underline-offset:2px;">
          &larr; Upload another file
        </a>
      </div>

    <?php elseif ($status === 'error'): ?>
      <div class="alert error" role="alert">
        <span class="alert-icon" aria-hidden="true">&#9888;</span>
        <div><?= htmlspecialchars($message) ?></div>
      </div>
      <?php if (!empty($webhookResults)): ?>
        <div class="webhook-status" aria-label="Webhook results">
          <?php foreach ($webhookResults as $i => $wh): ?>
            <div class="webhook-row <?= $wh['success'] ? 'ok' : 'fail' ?>">
              <span class="webhook-dot"></span>
              <span class="webhook-label">Endpoint <?= $i + 1 ?></span>
              <span class="webhook-code"><?= $wh['success'] ? 'OK' : 'HTTP ' . $wh['code'] ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div style="margin-top:20px; text-align:center;">
        <a href="?" style="font-size:13px; color:var(--muted); text-decoration:underline; text-underline-offset:2px;">
          &larr; Try again
        </a>
      </div>

    <?php else: ?>
      <form method="POST" enctype="multipart/form-data" id="uploadForm" aria-label="Upload weekly jobs file">

        <div class="dropzone" id="dropzone">
          <label for="jobfile" class="dropzone-label">
            <span class="drop-icon" aria-hidden="true">&#128202;</span>
            <span class="drop-title">Drop your Excel file here</span>
            <span class="drop-sub">or click to browse — .xlsx / .xls only</span>
          </label>
          <input type="file" name="jobfile" id="jobfile" accept=".xlsx,.xls" required aria-describedby="fileHelp">
          <div class="file-selected" id="fileSelected" aria-live="polite">
            <span aria-hidden="true">&#128206;</span>
            <span id="fileName"></span>
          </div>
        </div>
        <p id="fileHelp" class="sr-only">Accepted file types: Excel spreadsheets, .xlsx or .xls</p>

        <div id="previewArea"></div>

        <button type="submit" class="btn" id="submitBtn" disabled aria-disabled="true">
          <span class="spinner" aria-hidden="true"></span>
          <span class="btn-text">Send Jobs</span>
        </button>

      </form>

      <div class="info-strip" aria-label="Service statistics" id="defaultStats">
        <div class="info-item">
          <span class="info-item-val">8</span>
          <span class="info-item-label">Technicians</span>
        </div>
        <div class="info-item">
          <span class="info-item-val">&lt;60s</span>
          <span class="info-item-label">Processing</span>
        </div>
        <div class="info-item">
          <span class="info-item-val">100%</span>
          <span class="info-item-label">Automated</span>
        </div>
      </div>
    <?php endif; ?>
  </main>

  <footer class="footer" role="contentinfo">
    Powered by <a href="https://northerndesigners.co.uk" target="_blank" rel="noopener noreferrer">NORTHERN Design &amp; Digital Marketing<span class="sr-only"> (opens in new tab)</span></a>
    &nbsp;&middot;&nbsp; Any issues? Email <a href="mailto:dave@northerndesigners.co.uk">dave@northerndesigners.co.uk</a>
  </footer>

</div>

<!-- Confirmation overlay -->
<div class="confirm-overlay" id="confirmOverlay">
  <div class="confirm-box">
    <h3>Confirm Distribution</h3>
    <span class="job-count-highlight" id="confirmJobCount">0</span>
    <p>jobs will be sorted by priority and distributed to <strong>8 technicians</strong>. This cannot be undone.</p>
    <div id="confirmWarningNote" style="display:none; background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:10px 14px; border-radius:6px; font-size:12px; text-align:left; margin-bottom:16px; line-height:1.5;">
      <strong>&#9888; Sending with missing data:</strong> <span id="confirmMissingList"></span>
    </div>
    <div class="confirm-actions">
      <button type="button" class="btn btn-cancel" id="confirmCancel">Cancel</button>
      <button type="button" class="btn" id="confirmSend">Confirm &amp; Send</button>
    </div>
  </div>
</div>

<script>
const input       = document.getElementById('jobfile');
const dropzone    = document.getElementById('dropzone');
const fileSelected = document.getElementById('fileSelected');
const fileNameEl  = document.getElementById('fileName');
const submitBtn   = document.getElementById('submitBtn');
const form        = document.getElementById('uploadForm');
const previewArea = document.getElementById('previewArea');
const overlay     = document.getElementById('confirmOverlay');
const defaultStats = document.getElementById('defaultStats');

let previewData   = null;
let confirmed     = false;

function updateFile(file) {
  if (!file) return;
  fileNameEl.textContent = file.name;
  fileSelected.classList.add('visible');
  submitBtn.disabled = true;
  submitBtn.setAttribute('aria-disabled', 'true');
  fetchPreview(file);
}

async function fetchPreview(file) {
  previewArea.classList.add('visible');
  previewArea.innerHTML = '<div class="preview-loading"><span class="mini-spin"></span> Analyzing file&hellip;</div>';
  if (defaultStats) defaultStats.style.display = 'none';

  const fd = new FormData();
  fd.append('jobfile', file);
  fd.append('action', 'preview');

  try {
    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    previewData = data;
    renderPreview(data);
  } catch (err) {
    previewArea.innerHTML = '<div class="preview-error"><strong>Preview unavailable</strong> File selected — you can still send it.</div>';
    submitBtn.disabled = false;
    submitBtn.setAttribute('aria-disabled', 'false');
  }
}

const COLUMN_CONSEQUENCES = {
  'Location':     'Job locations will be blank in technician emails',
  'Full Address': 'Full addresses will be missing from emails',
  'Postcode':     'Jobs cannot be assigned to technicians — all will go unrouted',
  'Priority':     'Jobs cannot be sorted by priority — order will be random',
  'Tanks':        'Tank IDs will be missing from emails',
};

let warningsIgnored = false;

function renderPreview(d) {
  warningsIgnored = false;
  let html = '<div class="preview-card">';
  html += '<div class="preview-heading">File Analysis</div>';

  const hasMissing = d.missingColumns && d.missingColumns.length > 0;

  if (d.foundColumns && d.foundColumns.length > 0) {
    html += '<div style="font-size:11px;color:var(--muted);margin-bottom:12px;line-height:1.6;">';
    html += '<span style="font-weight:700;letter-spacing:0.5px;text-transform:uppercase;">Columns found:</span> ';
    html += d.foundColumns.map(c => '<code style="background:#eef5ff;padding:1px 5px;border-radius:3px;font-size:11px;">' + escHtml(c) + '</code>').join(' ');
    html += '</div>';
  }

  if (hasMissing) {
    html += '<div class="preview-warn-box" id="warnBox">';
    html += '<strong>&#9888; Missing columns detected (' + d.missingColumns.length + ')</strong>';
    html += '<ul class="preview-warn-list">';
    for (const col of d.missingColumns) {
      const consequence = COLUMN_CONSEQUENCES[col] || 'This data will be missing from the output';
      html += '<li><span class="warn-col">' + escHtml(col) + '</span>';
      html += '<span class="warn-consequence">' + escHtml(consequence) + '</span></li>';
    }
    html += '</ul>';
    html += '<div class="preview-warn-footer">The file will still be sent to Make.com but the data above will be incomplete. Technician emails may be missing information.</div>';
    html += '<button type="button" class="btn-ignore" id="ignoreWarningsBtn">&#9888; Ignore &amp; Continue Anyway</button>';
    html += '</div>';
  }

  if (d.duplicate) {
    html += '<div class="preview-dupe-warn">&#9888; This file was already uploaded at <strong>' +
      escHtml(d.duplicateTime) + '</strong> (' + escHtml(d.duplicateName) + '). You can still send it again.</div>';
  }

  if (d.jobCount > 0) {
    html += '<div class="preview-stats">';
    html += stat(d.jobCount, 'Jobs', 'total');
    html += stat(d.priority.high, 'High (7\u20139)', 'high');
    html += stat(d.priority.medium, 'Med (4\u20136)', 'med');
    html += stat(d.priority.low, 'Low (1\u20133)', 'low');
    html += stat(d.highestPriority, 'Highest', 'total');
    html += stat(d.lowestPriority, 'Lowest', 'total');
    html += '</div>';

    if (d.technicians && d.technicians.length > 0) {
      html += '<div class="preview-section-title">Technician Distribution</div>';
      html += '<ul class="preview-tech-list">';
      for (const t of d.technicians) {
        html += '<li><span class="tech-name">' + escHtml(t.name) + '</span><span class="tech-count">' + t.count + '</span></li>';
      }
      html += '</ul>';
    if (d.unassigned > 0) {
      html += '<div class="preview-unassigned">&#9888; ' + d.unassigned + ' job(s) with unrecognised postcodes</div>';
    }
    }

    if (d.rowErrors && d.rowErrors.length > 0) {
      html += '<div class="preview-section-title">Row Issues (' + d.rowErrors.length + ')</div>';
      html += '<details style="font-size:12px;color:#78350f;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:10px 12px;">';
      html += '<summary style="cursor:pointer;font-weight:700;color:#92400e;">&#9888; ' + d.rowErrors.length + ' row(s) with missing or invalid data</summary>';
      html += '<ul style="list-style:none;margin:8px 0 0;padding:0;max-height:180px;overflow-y:auto;">';
      for (const re of d.rowErrors) {
        const loc = re.location ? escHtml(re.location) : '\u2014';
        html += '<li style="padding:4px 0;border-bottom:1px solid #fde68a;display:flex;gap:6px;line-height:1.4;">';
        html += '<span style="font-weight:700;color:#92400e;min-width:48px;">Row ' + re.row + '</span>';
        html += '<span style="min-width:80px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + loc + '">' + loc + '</span>';
        html += '<span style="color:#a16207;">' + escHtml(re.issues.join(', ')) + '</span>';
        html += '</li>';
      }
      html += '</ul></details>';
    }
  } else if (d.valid) {
    html += '<div class="preview-error">No data rows found in the file.</div>';
  }

  html += '</div>';
  previewArea.innerHTML = html;

  if (d.valid && d.jobCount > 0) {
    if (hasMissing) {
      submitBtn.disabled = true;
      submitBtn.setAttribute('aria-disabled', 'true');

      const ignoreBtn = document.getElementById('ignoreWarningsBtn');
      if (ignoreBtn) {
        ignoreBtn.addEventListener('click', () => {
          warningsIgnored = true;
          ignoreBtn.textContent = '\u2713 Warnings acknowledged — you can send now';
          ignoreBtn.style.background = '#78350f';
          ignoreBtn.style.cursor = 'default';
          ignoreBtn.disabled = true;
          submitBtn.disabled = false;
          submitBtn.setAttribute('aria-disabled', 'false');
        });
      }
    } else {
      submitBtn.disabled = false;
      submitBtn.setAttribute('aria-disabled', 'false');
    }
  }
}

function stat(val, label, cls) {
  return '<div class="preview-stat ' + cls + '"><span class="preview-stat-val">' + val + '</span><span class="preview-stat-label">' + label + '</span></div>';
}

function escHtml(s) {
  const el = document.createElement('span');
  el.textContent = s;
  return el.innerHTML;
}

if (input) {
  input.addEventListener('change', () => updateFile(input.files[0]));
}

if (dropzone) {
  dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('drag-over'); });
  dropzone.addEventListener('dragleave', () => dropzone.classList.remove('drag-over'));
  dropzone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropzone.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) {
      const dt = new DataTransfer();
      dt.items.add(file);
      input.files = dt.files;
      updateFile(file);
    }
  });
}

if (form) {
  form.addEventListener('submit', (e) => {
    if (!confirmed) {
      e.preventDefault();
      if (previewData) {
        document.getElementById('confirmJobCount').textContent = previewData.jobCount;
      } else {
        document.getElementById('confirmJobCount').textContent = '?';
      }

      const warnNote = document.getElementById('confirmWarningNote');
      const warnList = document.getElementById('confirmMissingList');
      if (warningsIgnored && previewData && previewData.missingColumns && previewData.missingColumns.length > 0) {
        warnList.textContent = previewData.missingColumns.join(', ');
        warnNote.style.display = 'block';
      } else {
        warnNote.style.display = 'none';
      }

      overlay.classList.add('visible');
      return;
    }
    submitBtn.classList.add('loading');
    submitBtn.querySelector('.btn-text').textContent = 'Sending\u2026';
  });
}

const cancelBtn  = document.getElementById('confirmCancel');
const confirmBtn = document.getElementById('confirmSend');

if (cancelBtn) {
  cancelBtn.addEventListener('click', () => {
    overlay.classList.remove('visible');
  });
}

if (confirmBtn) {
  confirmBtn.addEventListener('click', () => {
    overlay.classList.remove('visible');
    confirmed = true;
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  });
}

if (overlay) {
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) overlay.classList.remove('visible');
  });
}
</script>
</body>
</html>
