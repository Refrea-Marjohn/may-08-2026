<?php
if (!function_exists('get_magic_quotes_runtime')) {
    function get_magic_quotes_runtime()
    {
        return false;
    }
}

require_once __DIR__ . '/vendor/autoload.php';

use setasign\Fpdi\Fpdi;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Please log in first.';
    exit;
}

$loan_amount_raw = str_replace(',', '', (string) ($_GET['loan_amount'] ?? ''));
$loan_amount = (float) $loan_amount_raw;

$dir = __DIR__ . '/downloads';
$preferred_template = $dir . '/Provident Fund Application Form.pdf';

$template_path = '';
if (is_file($preferred_template)) {
    $template_path = $preferred_template;
} else {
    $files = glob($dir . '/*.pdf');
    if (!empty($files)) {
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        if (is_file($files[0])) {
            $template_path = $files[0];
        }
    }
}

if ($template_path === '') {
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Form file not found.';
    exit;
}

$loan_amount_text = $loan_amount > 0 ? number_format($loan_amount, 2) : '';
$safe_name = trim((string) ($_SESSION['full_name'] ?? 'Borrower'));
$borrower_printed_name = preg_replace('/[^\x20-\x7E]/', '', $safe_name);
$borrower_surname = trim((string) ($_GET['borrower_surname'] ?? ''));
$borrower_first_name = trim((string) ($_GET['borrower_first_name'] ?? ''));
$borrower_mi = strtoupper(trim((string) ($_GET['borrower_mi'] ?? '')));
$borrower_mi = preg_replace('/[^A-Z]/', '', $borrower_mi);
if (strlen($borrower_mi) > 2) {
    $borrower_mi = substr($borrower_mi, 0, 2);
}
$borrower_position = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['borrower_position'] ?? '')));
$borrower_employee_no = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['borrower_employee_no'] ?? '')));
$borrower_employment_status = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['borrower_employment_status'] ?? '')));
$borrower_office = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['borrower_office'] ?? '')));
$borrower_birth_date = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['borrower_birth_date'] ?? '')));
$borrower_age = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['borrower_age'] ?? '')));
$borrower_monthly_salary = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['borrower_monthly_salary'] ?? '')));
$borrower_office_tel_no = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['borrower_office_tel_no'] ?? '')));
$borrower_years_in_service = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['borrower_years_in_service'] ?? '')));
$borrower_mobile_no = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['borrower_mobile_no'] ?? '')));
$borrower_home_address = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['borrower_home_address'] ?? '')));
$borrower_school_unit = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['borrower_school_unit'] ?? '')));
$borrower_service = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['borrower_service'] ?? '')));

$co_maker_surname = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['co_maker_surname'] ?? '')));
$co_maker_first_name = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['co_maker_first_name'] ?? '')));
$co_maker_mi = strtoupper(trim((string) ($_GET['co_maker_mi'] ?? '')));
$co_maker_mi = preg_replace('/[^A-Z]/', '', $co_maker_mi);
if (strlen($co_maker_mi) > 2) {
    $co_maker_mi = substr($co_maker_mi, 0, 2);
}
$co_maker_position = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['co_maker_position'] ?? '')));
$co_maker_employee_no = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['co_maker_employee_no'] ?? '')));
$co_maker_employment_status = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['co_maker_employment_status'] ?? '')));
$co_maker_office = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['co_maker_office'] ?? '')));
$co_maker_birth_date = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['co_maker_birth_date'] ?? '')));
$co_maker_age = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['co_maker_age'] ?? '')));
$co_maker_monthly_salary = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['co_maker_monthly_salary'] ?? '')));
$co_maker_office_tel_no = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['co_maker_office_tel_no'] ?? '')));
$co_maker_years_in_service = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['co_maker_years_in_service'] ?? '')));
$co_maker_mobile_no = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['co_maker_mobile_no'] ?? '')));
$co_maker_home_address = preg_replace('/[^\x20-\x7E]/', '', trim((string) ($_GET['co_maker_home_address'] ?? '')));

$co_maker_printed_name = trim($co_maker_first_name . ' ' . $co_maker_mi . ' ' . $co_maker_surname);

if ($borrower_surname === '' && $borrower_first_name === '' && $borrower_mi === '') {
    $full_name_raw = $safe_name;
    if (strpos($full_name_raw, ',') !== false) {
        $name_parts = explode(',', $full_name_raw, 2);
        $borrower_surname = trim($name_parts[0]);
        $given_parts = preg_split('/\s+/', trim($name_parts[1]));
        $borrower_first_name = trim((string) ($given_parts[0] ?? ''));
        $middle = trim((string) ($given_parts[1] ?? ''));
        $borrower_mi = $middle !== '' ? strtoupper(substr($middle, 0, 1)) : '';
    }
}

function pdf_cell_fit_center(Fpdi $pdf, float $x, float $y, float $w, float $h, string $text, float $maxFont = 9.6, float $minFont = 6.6): void
{
    $text = trim($text);
    if ($text === '') {
        return;
    }
    $font = $maxFont;
    $pdf->SetFont('Helvetica', '', $font);
    $padding = 1.2;
    $maxWidth = max(1.0, $w - ($padding * 2));
    while ($font > $minFont && $pdf->GetStringWidth($text) > $maxWidth) {
        $font -= 0.2;
        $pdf->SetFont('Helvetica', '', $font);
    }
    $pdf->SetXY($x, $y);
    $pdf->Cell($w, $h, $text, 0, 0, 'C');
}

function pdf_text_line_fit(Fpdi $pdf, float $x, float $y, float $w, float $h, string $text, float $maxFont = 9.0, float $minFont = 6.2): void
{
    $text = trim($text);
    if ($text === '') {
        return;
    }
    $font = $maxFont;
    $pdf->SetFont('Helvetica', '', $font);
    $maxWidth = max(1.0, $w - 1.0);
    while ($font > $minFont && $pdf->GetStringWidth($text) > $maxWidth) {
        $font -= 0.2;
        $pdf->SetFont('Helvetica', '', $font);
    }
    $pdf->SetXY($x, $y);
    $pdf->Cell($w, $h, $text, 0, 0, 'L');
}

function normalize_pdf_date(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        $dt = DateTime::createFromFormat('Y-m-d', $raw);
        if ($dt instanceof DateTime) {
            return $dt->format('m/d/Y');
        }
    }
    return $raw;
}

$borrower_birth_date = normalize_pdf_date($borrower_birth_date);
$co_maker_birth_date = normalize_pdf_date($co_maker_birth_date);

try {
    $pdf = new Fpdi();
    $page_count = $pdf->setSourceFile($template_path);

    for ($page = 1; $page <= $page_count; $page++) {
        $template_id = $pdf->importPage($page);
        $size = $pdf->getTemplateSize($template_id);
        $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
        $pdf->AddPage($orientation, [$size['width'], $size['height']]);
        $pdf->useTemplate($template_id);

        if ($page === 1) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY(48.8, 73.1);
            $pdf->Cell(85, 4.6, $loan_amount_text, 0, 0, 'L');

            $name_y = 128.9;
            pdf_cell_fit_center($pdf, 10.5, $name_y, 46.5, 4.6, $borrower_surname);
            pdf_cell_fit_center($pdf, 50.8, $name_y, 47.0, 4.6, $borrower_first_name);
            pdf_cell_fit_center($pdf, 91.6, $name_y, 16.0, 4.6, $borrower_mi, 9.6, 7.2);

            pdf_text_line_fit($pdf, 46.0, 145.0, 78.0, 4.5, $borrower_position);
            pdf_text_line_fit($pdf, 40.0, 152.0, 42.0, 4.5, $borrower_employee_no, 8.8, 6.4);
            pdf_text_line_fit($pdf, 90.5, 152.0, 26.0, 4.5, $borrower_employment_status, 8.4, 6.0);
            pdf_text_line_fit($pdf, 46.0, 156.7, 30.0, 4.5, $borrower_office, 8.4, 6.0);
            pdf_text_line_fit($pdf, 35.0, 163.0, 34.0, 4.5, $borrower_birth_date, 8.4, 6.0);
            pdf_text_line_fit($pdf, 94.0, 163.9, 11.0, 4.5, $borrower_age, 8.4, 6.0);
            pdf_text_line_fit($pdf, 40.0, 170.8, 28.0, 4.5, $borrower_monthly_salary, 8.4, 6.0);
            pdf_text_line_fit($pdf, 88.0, 170.8, 26.0, 4.5, $borrower_office_tel_no, 8.2, 6.0);
            pdf_text_line_fit($pdf, 40.0, 175.8, 26.0, 4.5, $borrower_years_in_service, 8.2, 6.0);
            pdf_text_line_fit($pdf, 79.0, 175.8, 26.0, 4.5, $borrower_mobile_no, 8.2, 6.0);
            pdf_text_line_fit($pdf, 46.0, 136.8, 78.0, 4.5, $borrower_home_address, 8.2, 6.0);

            $borrower_printed_name_caps = strtoupper(trim($borrower_printed_name));
            if ($borrower_printed_name_caps !== '') {
                $pdf->SetFont('Helvetica', 'B', 8.2);
                $pdf->SetXY(19.0, 272.0);
                $pdf->Cell(86.0, 4.5, $borrower_printed_name_caps, 0, 0, 'L');
            }

            $co_offset_x = 94.0;
            $co_offset_y = 0.0;
            $co_name_y = $name_y + $co_offset_y;
            pdf_cell_fit_center($pdf, 10.5 + $co_offset_x, $co_name_y, 46.5, 4.6, $co_maker_surname);
            pdf_cell_fit_center($pdf, 50.8 + $co_offset_x, $co_name_y, 47.0, 4.6, $co_maker_first_name);
            pdf_cell_fit_center($pdf, 91.6 + $co_offset_x, $co_name_y, 16.0, 4.6, $co_maker_mi, 9.6, 7.2);

            pdf_text_line_fit($pdf, 46.0 + $co_offset_x, 145.0 + $co_offset_y, 78.0, 4.5, $co_maker_position);
            pdf_text_line_fit($pdf, 40.0 + $co_offset_x, 152.0 + $co_offset_y, 42.0, 4.5, $co_maker_employee_no, 8.8, 6.4);
            pdf_text_line_fit($pdf, 90.5 + $co_offset_x, 152.0 + $co_offset_y, 26.0, 4.5, $co_maker_employment_status, 8.4, 6.0);
            pdf_text_line_fit($pdf, 46.0 + $co_offset_x, 156.7 + $co_offset_y, 30.0, 4.5, $co_maker_office, 8.4, 6.0);
            pdf_text_line_fit($pdf, 40.0 + $co_offset_x, 163.0 + $co_offset_y, 34.0, 4.5, $co_maker_birth_date, 8.4, 6.0);
            pdf_text_line_fit($pdf, 94.0 + $co_offset_x, 163.9 + $co_offset_y, 11.0, 4.5, $co_maker_age, 8.4, 6.0);
            pdf_text_line_fit($pdf, 44.0 + $co_offset_x, 170.8 + $co_offset_y, 28.0, 4.5, $co_maker_monthly_salary, 8.4, 6.0);
            pdf_text_line_fit($pdf, 88.0 + $co_offset_x, 170.8 + $co_offset_y, 26.0, 4.5, $co_maker_office_tel_no, 8.2, 6.0);
            pdf_text_line_fit($pdf, 44.0 + $co_offset_x, 175.8 + $co_offset_y, 26.0, 4.5, $co_maker_years_in_service, 8.2, 6.0);
            pdf_text_line_fit($pdf, 79.0 + $co_offset_x, 175.8 + $co_offset_y, 26.0, 4.5, $co_maker_mobile_no, 8.2, 6.0);
            pdf_text_line_fit($pdf, 46.0 + $co_offset_x, 136.8 + $co_offset_y, 78.0, 4.5, $co_maker_home_address, 8.2, 6.0);

            $co_maker_printed_name_caps = strtoupper(trim($co_maker_printed_name));
            if ($co_maker_printed_name_caps !== '') {
                $pdf->SetFont('Helvetica', 'B', 8.2);
                $pdf->SetXY(122.0, 272.0);
                $pdf->Cell(86.0, 4.5, $co_maker_printed_name_caps, 0, 0, 'L');
            }
        }

        if ($page === 3) {
            $pdf->SetTextColor(0, 0, 0);

            $p3_school_unit_x = 37.0;
            $p3_school_unit_y = 289.0;
            pdf_text_line_fit($pdf, $p3_school_unit_x, $p3_school_unit_y, 110.0, 4.5, $borrower_school_unit, 8.6, 6.2);

            $p3_service_x = 159.0;
            $p3_service_y = 287.0;
            pdf_text_line_fit($pdf, $p3_service_x, $p3_service_y, 55.0, 4.5, $borrower_service, 8.6, 6.2);

            // Coordinates are swapped intentionally to match the actual template labels on page 3.
            $p3_employee_no_x = 37.0;
            $p3_employee_no_y = 280.0;
            pdf_text_line_fit($pdf, $p3_employee_no_x, $p3_employee_no_y, 55.0, 4.5, $borrower_employee_no, 8.6, 6.2);

            $p3_status_x = 92.0;
            $p3_status_y = 280.0;
            pdf_text_line_fit($pdf, $p3_status_x, $p3_status_y, 55.0, 4.5, $borrower_employment_status, 8.6, 6.2);

            $borrower_printed_name_caps_p3 = strtoupper(trim($borrower_printed_name));
            if ($borrower_printed_name_caps_p3 !== '') {
                $pdf->SetFont('Helvetica', 'B', 8.2);
                $pdf->SetXY(155.0, 268.0);
                $pdf->Cell(86.0, 4.5, $borrower_printed_name_caps_p3, 0, 0, 'L');
            }
        }
    }

    $download_name = 'Provident_Fund_Application_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', ($safe_name !== '' ? $safe_name : 'Borrower')) . '.pdf';
    if (ob_get_length()) {
        ob_end_clean();
    }
    $pdf->Output('D', $download_name, true);
    exit;
} catch (Throwable $e) {
    error_log('[Provident PDF] Failed to generate PDF. user_id=' . (string) ($_SESSION['user_id'] ?? '') . ' template=' . $template_path . ' error=' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to generate PDF right now.';
    exit;
}
