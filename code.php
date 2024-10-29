<?php
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use TCPDF;

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

class DataFormatHandler {
    private $supported_formats = [
        'standard' => [
            'goal_number' => 0,
            'indicator' => 1,
            'target_value' => 2,
            'current_value' => 3,
            'progress_status' => 4
        ]
    ];

    public function processFile(array $file, string $format): array {
        if (!isset($this->supported_formats[$format])) {
            throw new Exception('Unsupported data format');
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, ['xlsx', 'xls', 'csv'])) {
            throw new Exception('Unsupported file format. Please upload CSV, XLS, or XLSX files.');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new Exception('Invalid file upload.');
        }

        try {
            if ($extension === 'csv') {
                return $this->processCSV($file['tmp_name'], $format);
            } else {
                return $this->processExcel($file['tmp_name'], $format);
            }
        } catch (Exception $e) {
            throw new Exception('Error processing file: ' . $e->getMessage());
        }
    }

    private function processExcel(string $filepath, string $format): array {
        try {
            $spreadsheet = IOFactory::load($filepath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            // Remove header row
            array_shift($rows);
            
            $data = [];
            foreach ($rows as $row) {
                if ($this->isValidRow($row)) {
                    $data[] = [
                        'goal_number' => $row[$this->supported_formats[$format]['goal_number']],
                        'indicator' => $row[$this->supported_formats[$format]['indicator']],
                        'target_value' => floatval($row[$this->supported_formats[$format]['target_value']]),
                        'current_value' => floatval($row[$this->supported_formats[$format]['current_value']]),
                        'progress_status' => $row[$this->supported_formats[$format]['progress_status']]
                    ];
                }
            }
            
            return $data;
        } catch (Exception $e) {
            throw new Exception('Error reading Excel file: ' . $e->getMessage());
        }
    }

    private function processCSV(string $filepath, string $format): array {
        if (($handle = fopen($filepath, 'r')) === false) {
            throw new Exception('Failed to open CSV file.');
        }

        $data = [];
        // Skip header row
        fgetcsv($handle);
        
        while (($row = fgetcsv($handle)) !== false) {
            if ($this->isValidRow($row)) {
                $data[] = [
                    'goal_number' => $row[$this->supported_formats[$format]['goal_number']],
                    'indicator' => $row[$this->supported_formats[$format]['indicator']],
                    'target_value' => floatval($row[$this->supported_formats[$format]['target_value']]),
                    'current_value' => floatval($row[$this->supported_formats[$format]['current_value']]),
                    'progress_status' => $row[$this->supported_formats[$format]['progress_status']]
                ];
            }
        }
        
        fclose($handle);
        return $data;
    }

    private function isValidRow(array $row): bool {
        return !empty($row) && 
               isset($row[0]) && 
               !empty($row[0]) && 
               count($row) >= 5;
    }
}

class SDGReportGenerator {
    private $pdf;

    public function __construct() {
        $this->pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $this->setupDocument();
    }

    private function setupDocument(): void {
        $this->pdf->SetCreator(PDF_CREATOR);
        $this->pdf->SetAuthor('SDGs report builder');
        $this->pdf->SetTitle('SDG Progress Report');
        $this->pdf->SetMargins(15, 15, 15);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetAutoPageBreak(true, 15);
    }

    public function generateReport(array $data, string $template = 'basic'): TCPDF {
        $this->addCoverPage();
        
        foreach ($data as $index => $goalData) {
            $this->addGoalPage($goalData, $index + 1);
        }
        
        return $this->pdf;
    }

    private function addCoverPage(): void {
        $this->pdf->AddPage();
        $this->pdf->SetFont('helvetica', 'B', 24);
        $this->pdf->Cell(0, 20, 'SDG Progress Report', 0, 1, 'C');
        $this->pdf->SetFont('helvetica', '', 14);
        $this->pdf->Cell(0, 10, 'Generated on: ' . date('Y-m-d'), 0, 1, 'C');
    }

    private function addGoalPage(array $goalData, int $index): void {
        $this->pdf->AddPage();
        
        // Goal Header
        $this->pdf->SetFont('helvetica', 'B', 16);
        $this->pdf->Cell(0, 15, "Goal {$goalData['goal_number']}", 0, 1, 'L');
        
        // Indicator
        $this->pdf->SetFont('helvetica', '', 12);
        $this->pdf->MultiCell(0, 10, "Indicator: {$goalData['indicator']}", 0, 'L');
        
        // Progress Information
        $this->pdf->Ln(5);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(0, 10, 'Progress Overview:', 0, 1, 'L');
        
        $this->pdf->SetFont('helvetica', '', 12);
        $this->pdf->Cell(60, 10, 'Target Value:', 0, 0, 'L');
        $this->pdf->Cell(0, 10, number_format($goalData['target_value'], 2), 0, 1, 'L');
        
        $this->pdf->Cell(60, 10, 'Current Value:', 0, 0, 'L');
        $this->pdf->Cell(0, 10, number_format($goalData['current_value'], 2), 0, 1, 'L');
        
        $this->pdf->Cell(60, 10, 'Status:', 0, 0, 'L');
        $this->pdf->Cell(0, 10, $goalData['progress_status'], 0, 1, 'L');
        
        // Add Progress Bar
        $this->addProgressBar($goalData);
    }

    private function addProgressBar(array $goalData): void {
        $progress = min(($goalData['current_value'] / $goalData['target_value']) * 100, 100);
        
        $this->pdf->Ln(10);
        
        // Progress bar background
        $this->pdf->SetFillColor(240, 240, 240);
        $this->pdf->Rect(15, $this->pdf->GetY(), 180, 10, 'F');
        
        // Progress bar fill
        $color = $this->getProgressColor($progress);
        $this->pdf->SetFillColor($color[0], $color[1], $color[2]);
        $this->pdf->Rect(15, $this->pdf->GetY(), 180 * ($progress / 100), 10, 'F');
        
        // Progress percentage
        $this->pdf->SetY($this->pdf->GetY() + 15);
        $this->pdf->Cell(0, 10, round($progress) . '% Progress Towards Target', 0, 1, 'C');
    }

    private function getProgressColor(float $progress): array {
        if ($progress >= 75) {
            return [76, 175, 80]; // Green
        } elseif ($progress >= 50) {
            return [255, 152, 0]; // Orange
        } else {
            return [244, 67, 54]; // Red
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate file upload
        if (!isset($_FILES['dataFile']) || $_FILES['dataFile']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload failed.');
        }

        // Validate template selection
        if (!isset($_POST['templateSelect']) || empty($_POST['templateSelect'])) {
            throw new Exception('Template selection is required.');
        }

        // Process the uploaded file
        $dataHandler = new DataFormatHandler();
        $data = $dataHandler->processFile($_FILES['dataFile'], 'standard');

        // Generate the report
        $generator = new SDGReportGenerator();
        $pdf = $generator->generateReport($data, $_POST['templateSelect']);

        // Output the PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="sdg_report.pdf"');
        $pdf->Output('sdg_report.pdf', 'D');
        exit;

    } catch (Exception $e) {
        session_start();
        $_SESSION['error'] = $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SDGs report builder</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
        }
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }
        .card {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: bold;
        }
        input[type="file"],
        select {
            width: 100%;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            background-color: #fff;
        }
        select {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 1em;
        }
        button {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        button:hover {
            background-color: #45a049;
        }
        .error {
            color: #dc3545;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #dc3545;
            border-radius: 4px;
            background-color: #f8d7da;
        }
        .file-info {
            font-size: 0.9em;
            color: #666;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h3>SDGs report builder</h3>
        <div class="card">
            <?php
            session_start();
            if (isset($_SESSION['error'])) {
                echo '<div class="error">' . $_SESSION['error'] . '</div>';
                unset($_SESSION['error']);
            }
            ?>
            <form id="reportBuilderForm" action="" method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="dataFile">Upload Data File</label>
                    <input type="file" id="dataFile" name="dataFile" accept=".csv,.xlsx,.xls" required>
                    <div class="file-info">Supported formats: CSV, XLS, XLSX</div>
                </div>

                <div class="form-group">
                    <label for="templateSelect">Report Template</label>
                    <select id="templateSelect" name="templateSelect" required>
                        <option value="">Select a template...</option>
                        <option value="basic">Basic Report</option>
                        <option value="detailed">Detailed Report</option>
                        <option value="executive">Executive Summary</option>
                    </select>
                </div>

                <button type="submit">Generate Report</button>
            </form>
        </div>
    </div>
</body>
</html>
	 


