<?php
session_start();

// Include Composer's autoloader
require 'vendor/autoload.php';

include 'db.php';

$teacher_name = $_SESSION['teacher_name'] ?? '';

// Function to save questions to the database using prepared statements
function saveQuestionsToDatabase($questions, $exam_title, $subject, $timer, $teacher_name, $conn) {
    // Prepare statement for inserting exam title into the exam table
    $stmt_exam = $conn->prepare("INSERT INTO exam (title, subject, timer, teacher) VALUES (?, ?, ?, ?)");
    if (!$stmt_exam) {
        die("Error preparing statement: " . $conn->error);
    }
    $stmt_exam->bind_param("ssis", $exam_title, $subject, $timer, $teacher_name);
    $stmt_exam->execute();
    $exam_id = $stmt_exam->insert_id; // Get the inserted exam_id
    $stmt_exam->close();

    // Prepare statement for inserting assignments
    $stmt_assign = $conn->prepare("INSERT INTO assignments (qn, question, opt1, opt2, opt3, opt4, answer, exam_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt_assign) {
        die("Error preparing statement: " . $conn->error);
    }

    // Execute the statement for inserting questions
    foreach ($questions as $question) {
        $qn = $question['qn'];
        $question_text = $question['question'];
        $opt1 = $question['opt1'];
        $opt2 = $question['opt2'];
        $opt3 = $question['opt3'];
        $opt4 = $question['opt4'];
        $answer = $question['answer'];

        // Bind parameters
        $stmt_assign->bind_param("issssssi", $qn, $question_text, $opt1, $opt2, $opt3, $opt4, $answer, $exam_id);

        // Execute the statement
        $stmt_assign->execute();
    }

    // Close the statement
    $stmt_assign->close();
}

// Function to extract text from uploaded files
function extractText($file_path, $file_type) {
    $text = "";

    try {
        if ($file_type == 'pdf') {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($file_path);
            $text = $pdf->getText();
        } elseif ($file_type == 'docx') {
            $phpWord = IOFactory::load($file_path);
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if (method_exists($element, 'getElements')) {
                        foreach ($element->getElements() as $childElement) {
                            if (method_exists($childElement, 'getText')) {
                                $text .= $childElement->getText() . "\n";
                            }
                        }
                    } elseif (method_exists($element, 'getText')) {
                        $text .= $element->getText() . "\n";
                    }
                }
            }
        } elseif ($file_type == 'txt') {
            $text = file_get_contents($file_path);
        }
    } catch (Exception $e) {
        die("Error extracting text: " . $e->getMessage());
    }

    return $text;
}

// Function to parse extracted text into questions and options
function parseQuestions($text, $num_questions) {
    $questions = [];
    $lines = explode("\n", $text);
    $current_question = '';
    $current_options = [];
    $qn_counter = 1;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // Match question lines starting with numbers (e.g., 1. What is...)
        if (preg_match('/^\d+\.\s+(.*)/', $line, $matches)) {
            // If there's an existing question, save it
            if ($current_question && count($questions) < $num_questions) {
                $questions[] = formatQuestion($current_question, $current_options, $qn_counter);
                $qn_counter++;
            }
            $current_question = $matches[1];
            $current_options = [];
        }
        // Match option lines starting with letters (e.g., a) Option A)
        elseif (preg_match('/^[a-dA-D]\)\s+(.*)/', $line, $matches)) {
            if (count($current_options) < 4) { // Assuming 4 options per question
                $current_options[] = $matches[1];
            }
        }
        // If line doesn't match question or option, append to current question
        else {
            $current_question .= ' ' . $line;
        }

        // Stop parsing if desired number of questions is reached
        if (count($questions) >= $num_questions) {
            break;
        }
    }

    // Add the last question
    if ($current_question && count($questions) < $num_questions) {
        $questions[] = formatQuestion($current_question, $current_options, $qn_counter);
    }

    return $questions;
}

// Helper function to format a question
function formatQuestion($question_text, $options, $qn) {
    // Placeholder for correct answer. Teachers can set this later.
    return [
        'qn' => $qn,
        'question' => $question_text,
        'opt1' => $options[0] ?? '',
        'opt2' => $options[1] ?? '',
        'opt3' => $options[2] ?? '',
        'opt4' => $options[3] ?? '',
        'answer' => '' // To be set by the teacher
    ];
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['upload_file'])) {
        // Handle file upload
        if (isset($_FILES['exam_file']) && $_FILES['exam_file']['error'] == 0) {
            $allowed = ['pdf', 'docx', 'txt'];
            $file_name = $_FILES['exam_file']['name'];
            $file_tmp = $_FILES['exam_file']['tmp_name'];
            $file_size = $_FILES['exam_file']['size'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if (in_array($file_ext, $allowed)) {
                // Move the uploaded file to a temporary directory
                $upload_dir = 'uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $file_path = $upload_dir . uniqid() . '.' . $file_ext;
                move_uploaded_file($file_tmp, $file_path);

                // Extract text from the file
                $extracted_text = extractText($file_path, $file_ext);

                // Get number of questions to extract
                $num_questions = isset($_POST['num_questions']) ? (int)$_POST['num_questions'] : 0;
                if ($num_questions <= 0) {
                    die("Invalid number of questions.");
                }

                // Parse questions
                $parsed_questions = parseQuestions($extracted_text, $num_questions);

                // Store parsed questions in session for confirmation/editing
                $_SESSION['parsed_questions'] = $parsed_questions;
                $_SESSION['exam_title'] = $_POST['exam_title'] ?? '';
                $_SESSION['subject'] = $_POST['subject'] ?? '';
                $_SESSION['timer'] = isset($_POST['timer']) ? (int)$_POST['timer'] : 0;

                // Redirect to confirmation page or display below
            } else {
                $error = "Invalid file type. Only PDF, DOCX, and TXT are allowed.";
            }
        } else {
            $error = "Error uploading file.";
        }
    } elseif (isset($_POST['save_exam'])) {
        // Handle saving the exam to the database
        if (isset($_SESSION['parsed_questions'], $_POST['exam_title'], $_POST['subject'], $_POST['timer'])) {
            $exam_title = $_POST['exam_title'];
            $subject = $_POST['subject'];
            $timer = (int)$_POST['timer'];
            $questions = $_SESSION['parsed_questions'];

            // Update answers based on user input
            foreach ($questions as &$question) {
                $qn = $question['qn'];
                if (isset($_POST['answer_' . $qn])) {
                    $selected_option = $_POST['answer_' . $qn];
                    switch ($selected_option) {
                        case '1':
                            $question['answer'] = $question['opt1'];
                            break;
                        case '2':
                            $question['answer'] = $question['opt2'];
                            break;
                        case '3':
                            $question['answer'] = $question['opt3'];
                            break;
                        case '4':
                            $question['answer'] = $question['opt4'];
                            break;
                        default:
                            $question['answer'] = '';
                    }
                }
            }

            // Save to database
            saveQuestionsToDatabase($questions, $exam_title, $subject, $timer, $teacher_name, $conn);

            // Clear session variables
            unset($_SESSION['parsed_questions'], $_SESSION['exam_title'], $_SESSION['subject'], $_SESSION['timer']);

            // Redirect to teacher.html page
            header("Location: teacher.html");
            exit();
        } else {
            $error = "Missing exam details or questions.";
        }
    }
}

// Close database connection at the end
// (Moved to the end to ensure it's closed after all operations)
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Exam Automatically</title>
    <style>
        /* [Include your existing CSS styles here] */

        :root {
            --primary-color: #6366f1;
            --primary-dark: #4f46e5;
            --text-color: #333;
            --bg-color: #f3f4f6;
            --white: #ffffff;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-color);
            margin: 0;
            padding: 0;
            color: var(--text-color);
        }

        .container {
            max-width: 800px;
            margin: 40px auto;
            padding: 30px;
            background-color: var(--white);
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        h1 {
            text-align: center;
            color: var(--primary-color);
            margin-bottom: 30px;
            font-size: 2.5em;
        }

        form {
            margin-top: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--primary-color);
        }

        input[type="text"], input[type="number"], input[type="file"], select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }

        input[type="text"]:focus, input[type="number"]:focus, input[type="file"]:focus, select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        input[type="submit"], button {
            background-color: var(--primary-color);
            color: var(--white);
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            font-weight: 600;
            transition: background-color 0.3s ease;
            display: block;
            margin: 30px auto 0;
        }

        input[type="submit"]:hover, button:hover {
            background-color: var(--primary-dark);
        }

        .question-block {
            background-color: #f8fafc;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            border-left: 4px solid var(--primary-color);
        }

        .error {
            color: red;
            text-align: center;
            margin-bottom: 20px;
        }

        .success {
            color: green;
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Create Exam Automatically</h1>

        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['parsed_questions'])): ?>
            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="form-group">
                    <label for="exam_title">Exam Title:</label>
                    <input type="text" id="exam_title" name="exam_title" value="<?php echo htmlspecialchars($_SESSION['exam_title']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="subject">Subject:</label>
                    <input type="text" id="subject" name="subject" value="<?php echo htmlspecialchars($_SESSION['subject']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="timer">Timer (minutes):</label>
                    <input type="number" id="timer" name="timer" value="<?php echo htmlspecialchars($_SESSION['timer']); ?>" required>
                </div>

                <?php foreach ($_SESSION['parsed_questions'] as $question): ?>
                    <div class="question-block">
                        <label>Question <?php echo $question['qn']; ?>:</label>
                        <p><?php echo htmlspecialchars($question['question']); ?></p>
                        <label>Options:</label>
                        <select name="answer_<?php echo $question['qn']; ?>" required>
                            <option value="">Select Correct Answer</option>
                            <option value="1"><?php echo htmlspecialchars($question['opt1']); ?></option>
                            <option value="2"><?php echo htmlspecialchars($question['opt2']); ?></option>
                            <option value="3"><?php echo htmlspecialchars($question['opt3']); ?></option>
                            <option value="4"><?php echo htmlspecialchars($question['opt4']); ?></option>
                        </select>
                    </div>
                <?php endforeach; ?>

                <input type="submit" name="save_exam" value="Save Exam">
            </form>
        <?php else: ?>
            <form method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="form-group">
                    <label for="exam_file">Upload Exam File (PDF, DOCX, TXT):</label>
                    <input type="file" id="exam_file" name="exam_file" accept=".pdf, .docx, .txt" required>
                </div>
                <div class="form-group">
                    <label for="num_questions">Number of Questions to Extract:</label>
                    <input type="number" id="num_questions" name="num_questions" min="1" required>
                </div>
                <input type="submit" name="upload_file" value="Upload and Extract">
            </form>
        <?php endif; ?>
    </div>
</body>
</html>

<?php
// Close database connection
$conn->close();
?>


