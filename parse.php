<?php
/**
 * IPPcode19 parser
 * @author Dominik Večeřa <xvecer23@stud.fit.vutbr.cz>
 */

/**
 * Function definitions
 * -----------------------------------------------------------------------------
 */

/**
 * Output error caused by too many arguments given to an instruction and exit.
 * @param $func string Function with too many arguments
 */
function argument_err($func)
{
    fwrite(STDERR, "Too many arguments passed to function " . strtoupper($func) . ".\n");
    exit(23);
}

/**
 * Output generic parser error with a specific message and exit.
 * @param $msg string Output message
 */
function parser_err($msg)
{
    fwrite(STDERR, $msg . "\n");
    exit(23);
}

/**
 * Output error message during evaluation of statistic expansion arguments,
 * close opened file and exit.
 * @param $file resource Opened file
 * @param $code int Error code
 * @param $msg string Output message
 */
function stats_err($file, $code, $msg)
{
    fwrite(STDERR, $msg . "\n");
    fclose($file);
    exit($code);
}

/**
 * Check validity of label declaration.
 * @param $data string Label to be checked
 */
function check_label($data)
{
    if (preg_match("#[0-9]#", $data[0])) {
        parser_err("Label name cannot start with a number.");
    }

    if (!preg_match("#^[a-zA-Z0-9_\-\$\&\%\*\!\?]+$#", $data)) {
        parser_err("Incorrect character in label name.");
    }
}

/**
 * Check validity of variable declaration.
 * @param $data string Variable to be checked
 */
function check_var($data)
{
    global $frames;

    $data = explode("@", $data, 2);

    if (!in_array($data[0], $frames)) {
        parser_err("Wrong memory frame definition.");
    }

    if (preg_match("#[0-9]#", $data[1][0])) {
        parser_err("Variable name cannot start with a number.");
    }

    if (!preg_match("#^[a-zA-Z0-9_\-\$\&\%\*\!\?]+$#", $data[1])) {
        parser_err("Incorrect character in variable name.");
    }
}

/**
 * Check validity of symbol declaration.
 * @param $data string Symbol to be checked
 * @return array Array containing type and value of symbol
 */
function check_symb($data)
{
    $data = explode("@", $data, 2);

    global $frames;

    if (in_array($data[0], $frames)) { // var symbol
        $data[1] = implode("@", $data);
        check_var($data[1]);
        $data[0] = "var";
        return $data;
    }

    if ($data[0] == "nil") { // int symbol

        if ($data[1] != "nil") {
            parser_err("Nil variable can only contain \"nil\" value.");
        }
        return $data;
    } else if ($data[0] == "bool") { // bool symbol

        if (!($data[1] == "true" || $data[1] == "false")) {
            parser_err("Boolean value has to be \"true\" or \"false\".");
        }
        return $data;
    } else if ($data[0] == "int") { // int symbol

        if (!preg_match("#^(\+|\-)?[0-9]+$#", $data[1])) {
            parser_err("Wrong integer variable format.");
        }
        return $data;
    }

    if ($data[0] == "string") { // string symbol

        if (strpos($data[1], "\\") !== false) {

            if (!preg_match("/\\\\[0-9]{3}/", $data[1], $matches, PREG_OFFSET_CAPTURE)) {
                parser_err("Wrong definition of escape sequence.");
            } else {
                return $data;
            }
        }

    } else {
        parser_err("Unknown symbol type.");
    }
    return $data;
}

/**
 * Check constant type declaration validity.
 * @param $data string Type of constant
 */
function check_type($data)
{
    $types = array("int", "bool", "string");

    if (!in_array($data, $types)) {
        parser_err("Wrong type definition.");
    }
}

/**
 * Start an XMLWriter instruction element with given information.
 * @param $opcode string Operation code of the instruction
 * @param $number int Number of instruction in the program
 */
function start_instruction($opcode, $number)
{
    global $xmldata;

    $xmldata->startElement("instruction");
    $xmldata->startAttribute("order");
    $xmldata->text($number);
    $xmldata->endAttribute();
    $xmldata->startAttribute("opcode");
    $xmldata->text($opcode);
    $xmldata->endAttribute();
}

/**
 * Add an argument to the current instruction.
 * @param $number int Number of argument in the instruction
 * @param $type string Type of argument
 * @param $value string Value of argument
 */
function add_argument($number, $type, $value)
{
    global $xmldata;

    switch ($number) {
        case 1:
            $xmldata->startElement("arg1");
            break;

        case 2:
            $xmldata->startElement("arg2");
            break;

        case 3:
            $xmldata->startElement("arg3");
            break;
    }

    $xmldata->startAttribute("type");
    $xmldata->text($type);
    $xmldata->endAttribute();
    $xmldata->text($value);
    $xmldata->endElement();

}

/**
 * End the current XMLWriter instruction element.
 */
function end_instruction()
{
    global $xmldata;
    $xmldata->endElement();
}

/**
 * Program starts here
 * -----------------------------------------------------------------------------
 */

/**
 * Get program arguments (help / statistic expansion)
 */
$options = array("stats:", "loc", "comments", "labels", "jumps", "help");
$arguments = getopt("", $options);
$options[0] = "stats"; // delete : required by getopt from first option

if (!empty($arguments)) {

    if (array_key_exists("help", $arguments)) {

        if (is_array($arguments["help"])) {
            fwrite(STDERR, "Multiple declarations of --help argument.\n");
            exit(10);
        }

        unset($argv[0]);
        for ($i = 1; $i <= count($argv); $i++){
            if (!preg_match("#^\-\-help#", $argv[$i])) {
                fwrite(STDERR, "--help argument cannot be combined with other arguments.\n");
                exit(10);
            }

            if (preg_match("#^\-\-help\=#", $argv[$i])){
                fwrite(STDERR, "--help argument cannot have a value.\n");
                exit(10);
            }
        }

        echo
        "Filter script parse.php loads code from standard input (stdin)
in the IPPcode19 language, checks lexical and syntactic correctness
of the code and outputs the XML representation of the program
to standard output (stdout). No additional arguments are required.
-------------------------------------------------------------------
Statistics: --stats=file
code statistics will be written to *file*

Parameters (--stats argument is required):
--loc       lines of code
--comments  lines with comments
--labels    number of unique defined labels
--jumps     number of conditional and unconditional jumps\n";
        exit(0);
    }
}

// load entire input at once and split it into an array
$code = file('php://stdin', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$comments = 0;

// get header
if (strpos($code[0], "#")) {
    $line = trim(strstr($code[0], "#", true));
    $comments++;
} else {
    $line = trim($code[0]);
}

// check correct header
if (strtolower($line) != ".ippcode19") {
    fwrite(STDERR, "Wrong file header.\n");
    exit(21);
}

// initialize XML object
$xmldata = new XMLWriter;
$xmldata->openMemory();
$xmldata->setIndent(true);
$xmldata->setIndentString("    ");
$xmldata->startDocument("1.0", "UTF-8");
$xmldata->startElement("program");
$xmldata->startAttribute("language");
$xmldata->text("IPPcode19");
$xmldata->endAttribute();

$frames = array("GF", "LF", "TF");

$inst_order = 1; // order of current instruction
$loc = 0; // lines of code
$labels = array();
$jumps = 0;

/**
 * Go through the code line by line using a finite state automaton
 */
for ($i = 1; $i < sizeof($code); $i++) {
    $line = $code[$i];

    if ($line[0] == "#") { // line with comment only
        $comments++;
        continue;
    }

    if (preg_match("#^[\s]+$#", $line)) // line with whitespace only
        continue;

    if (preg_match("#^[\s]+\##", $line)) // line with whitespace and comment
        continue;

    if (strpos($line, "#")) { // get rid of comment and increment comment count
        $comments++;
        $line = strstr($line, "#", true);
    }

    /**
     * preg_split splits string into array elements with whitespace as the delimiter
     * array_filter gets rid of empty elements that were created (redundant whitespace etc.)
     */
    $line = array_filter(preg_split("/[\s]+/", trim($line)));

    switch (strtoupper($line[0])) {

        case "MOVE":
        case "NOT":
        case "INT2CHAR":
        case "STRLEN":
        case "TYPE":
            if (isset($line[3]))
                argument_err($line[0]);

            start_instruction(strtoupper($line[0]), $inst_order);

            check_var($line[1]);
            add_argument(1, "var", $line[1]);

            $symbol = check_symb($line[2]);
            add_argument(2, $symbol[0], $symbol[1]);

            end_instruction();

            break;

        case "RETURN":
        case "CREATEFRAME":
        case "PUSHFRAME":
        case "POPFRAME":
        case "BREAK":
            if (isset($line[1]))
                argument_err($line[0]);

            start_instruction(strtoupper($line[0]), $inst_order);
            end_instruction();

            break;

        case "DEFVAR":
        case "POPS":
            if (isset($line[2]))
                argument_err($line[0]);

            start_instruction(strtoupper($line[0]), $inst_order);

            check_var($line[1]);
            add_argument(1, "var", $line[1]);

            end_instruction();

            break;

        case "JUMP":
            $jumps++;
        case "CALL":
            if (isset($line[2]))
                argument_err($line[0]);

            start_instruction(strtoupper($line[0]), $inst_order);

            check_label($line[1]);
            add_argument(1, "label", $line[1]);

            end_instruction();

            break;

        case "PUSHS":
        case "WRITE":
        case "EXIT":
        case "DPRINT":
            if (isset($line[2]))
                argument_err($line[0]);

            start_instruction(strtoupper($line[0]), $inst_order);

            $symbol = check_symb($line[1]);
            add_argument(1, $symbol[0], $symbol[1]);

            end_instruction();
            break;

        case "ADD":
        case "SUB":
        case "MUL":
        case "IDIV":
        case "LT":
        case "GT":
        case "EQ":
        case "AND":
        case "OR":
        case "STRI2INT":
        case "CONCAT":
        case "GETCHAR":
        case "SETCHAR":
            if (isset($line[4]))
                argument_err($line[0]);

            start_instruction(strtoupper($line[0]), $inst_order);

            check_var($line[1]);
            add_argument(1, "var", $line[1]);

            $symbol = check_symb($line[2]);
            add_argument(2, $symbol[0], $symbol[1]);

            $symbol = check_symb($line[3]);
            add_argument(3, $symbol[0], $symbol[1]);

            end_instruction();

            break;

        case "READ":
            if (isset($line[3]))
                argument_err($line[0]);

            start_instruction(strtoupper($line[0]), $inst_order);

            check_var($line[1]);
            add_argument(1, "var", $line[1]);

            check_type($line[2]);
            add_argument(2, "type", $line[2]);

            end_instruction();

            break;

        case "LABEL":
            if (isset($line[2]))
                argument_err($line[0]);

            start_instruction(strtoupper($line[0]), $inst_order);

            check_label($line[1]);
            add_argument(1, "label", $line[1]);

            end_instruction();

            if (!in_array($line[1], $labels)) {
                $labels[] = $line[1];
            }

            break;

        case "JUMPIFEQ":
        case "JUMPIFNEQ":
            if (isset($line[4]))
                argument_err($line[0]);

            start_instruction(strtoupper($line[0]), $inst_order);

            check_label($line[1]);
            add_argument(1, "label", $line[1]);

            $symbol = check_symb($line[2]);
            add_argument(2, $symbol[0], $symbol[1]);

            $symbol = check_symb($line[3]);
            add_argument(3, $symbol[0], $symbol[1]);

            end_instruction();

            $jumps++;

            break;

        /**
         * Unknown or unsupported opcode - exit with error.
         */

        default:
            fwrite(STDERR, "Wrong operation code.\n");
            exit(22);
    }

    $loc++;
    $inst_order++;
}

/**
 * End <program> element and the XML document itself, since writing to it is done
 */
$xmldata->endElement();
$xmldata->endDocument();

/**
 * Statistic expansion
 */

unset($argv[0]);
for ($i = 1; $i <= count($argv); $i++){
    if (preg_match("#^\-\-(loc|comments|labels|jumps)\=#", $argv[$i])) {
        fwrite(STDERR, "Statistic arguments except --stats cannot have a value.\n");
        exit(10);
    }

    if (!preg_match("#^\-\-(loc|comments|labels|jumps|stats)#", $argv[$i])) {
        fwrite(STDERR, "Unknown argument set.\n");
        exit(10);
    }
}

if (array_key_exists("stats", $arguments)) {

    if (is_array($arguments["stats"])) {
        fwrite(STDERR, "Multiple declarations of --stats argument.\n");
        exit(10);
    }

    // delete --help from possible arguments list, since it has already been dealt with
    array_pop($options);

    $statfile = fopen($arguments["stats"], "w");
    if (!$statfile) {
        fwrite(STDERR, "Failed to open specified statistics file.\n");
        exit(11);
    }

    // read input parameters one by one
    foreach ($arguments as $key => $value) {

        if (in_array($key, $options)) {

            switch ($key) {
                case "stats":
                    break;

                case "loc":
                    if (!fwrite($statfile, $loc . "\n")) {
                        stats_err($statfile, 12, "Failed to write to specified statistics file.");
                    }
                    break;

                case "comments":
                    if (!fwrite($statfile, $comments . "\n")) {
                        stats_err($statfile, 12, "Failed to write to specified statistics file.");
                    }
                    break;

                case "labels":
                    if (!fwrite($statfile, count($labels) . "\n")) {
                        stats_err($statfile, 12, "Failed to write to specified statistics file.");
                    }
                    break;

                case "jumps":
                    if (!fwrite($statfile, $jumps . "\n")) {
                        stats_err($statfile, 12, "Failed to write to specified statistics file.");
                    }
                    break;

                default:
                    stats_err($statfile, 10, "Error occured during argument processing.");
            }

        } else {
            stats_err($statfile, 10, "Unknown argument set.");
        }
    }

    fclose($statfile);
} else {

    if (array_key_exists("loc", $arguments) || array_key_exists("comments", $arguments) ||
        array_key_exists("labels", $arguments) || array_key_exists("jumps", $arguments)) {
        fwrite(STDERR, "Statistic expansion argument set without --stats argument.\n");
        exit(10);
    }
}

/**
 * Output XML result if everything is correct
 */
echo $xmldata->outputMemory(true);