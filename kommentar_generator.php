<?php

/* The generator is based upon two data sources:
 * DBpedia-de (pre-filtered)
 * An XML file named "generator_data.xml" with nested random and condition elements.
 * 
 * For example, in the XML, there mighte be something like this:
 * <choice>
     <option>auch heute</option>
     <option>völlig zurecht</option>
     <option>fragwürdigerweise</option>
   </choice>
 * Whenever the parser function encounteres an element like such, it randomly picks one
 * of the options.
 * Some option elements have conditions, like condition="yes_is_name". This is where the DBpedia data
 * gets interesting: We first check whether the keyword is equal to a person name entry. If
 * yes, we set $_GET['is_name'] = 'yes_is_name'; Thus, whenever the XML parser functions runs
 * into an option with the condition "yes_is_name" it is taken into account. If the condition is not met,
 * it can't be picked.
 * 
 * In a determination element, nothing is done randomly, the content is picked upon the condition
 * For example, within an <option condition="yes_is_name"> element we could set something like this:
 *  <determination field="person_gender">
       <if equal_to="male">seinem</if>
        <if equal_to="female">ihrem</if>
    </determination>
 * 
 * The DBpedia data is stored as RDF triples, deployed within Fuseki Triple Store. It is here accessed via ARC2.
 * 
 *  When the generator looks for the keyword input by the user
 *  in the DBpedia data, three things could happen:
 * 
 * 1. The keyword is found, but the result is not a person.
 * 2. The keyword is equal to the name of a person
 * 3. The keyword is not found at all.
 * 
 * In the first case, two random categories are picked from the result set.
 * In the second case, also some other information are taken into account form the DBpedia data:
 * Canonical name, year of death, gender and so on.
 * 
 * In the third case, nothing from DBpedia is used at all.
 * 
 * After checking DB pedia, the XML parser function is called and the result is put out.
 */

include '../fillform.php'; /* http://www.onlamp.com/pub/a/php/2006/03/16/autofill-forms.html?page=1 */
include_once("arc2/ARC2.php"); /* https://github.com/semsol/arc2 */

check_input();
set_article_ending_by_detecting_type_of_seminar();
check_input_with_DB_pedia_data();
$parsing_results = access_xml_for_title_and_text();
$output_string = prepare_output($parsing_results);
output($output_string);


function check_input()
{
 if($_GET['keyword'] == NULL) 
    { 
      $output_string = '<div class="error">';
      $output_string .=  'Bitte Schlagwort eingeben.';
      $output_string .=  '</div>';
      output($output_string);
    }
}

function set_article_ending_by_detecting_type_of_seminar()
{
$kind_of_seminar_safe = replace_dangerous_chars($_GET['kind_of_seminar']);

switch ($kind_of_seminar_safe)
 {
  case 'Übung':
  case 'Vorlesung':   
  $_GET['r_or_m'] = 'r';
  $_GET['r_or_s'] = 'r';
  $_GET['article_after_d'] = 'ie';
  $_GET['genitive_ending'] = '';
  break;

  case 'Proseminar':
  case 'Hauptseminar':
  $_GET['r_or_m'] = 'm';
  $_GET['r_or_s'] = 's';
  $_GET['article_after_d'] = 'as';
  $_GET['genitive_ending'] = 's';
  break;

  default:
      $output_string = '<div class="error">';
      $output_string .=  'Falsche Eingabe bei Veranstaltungsart.';
      $output_string .=  '</div>';
      output($output_string);
  break;
 }
}

function check_input_with_DB_pedia_data()
{
$keyword_safe = replace_dangerous_chars($_GET['keyword']);

$query_string = 'PREFIX rdfs:  <http://www.w3.org/2000/01/rdf-schema#>
PREFIX prop-de: <http://de.dbpedia.org/property/>
PREFIX dcterms: <http://purl.org/dc/terms/>
PREFIX foaf: <http://xmlns.com/foaf/0.1/>
    
CONSTRUCT
{
 ?s rdfs:label ?name .
 ?s prop-de:sterbedatum ?todesjahr .
 ?s dcterms:subject ?kategorie .
 ?s foaf:gender ?geschlecht .
}

WHERE
{
 {
  SELECT ?s
  WHERE
  {
   ?s prop-de:name ?alle_namen .
   FILTER (contains(?alle_namen," ' . $keyword_safe .  '"))
  }
  ORDER BY rand()
  LIMIT 10
 }
 ?s rdfs:label ?name .
 ?s dcterms:subject ?kategorie .

 OPTIONAL { ?s prop-de:sterbedatum ?todesjahr . }
 OPTIONAL { ?s foaf:gender ?geschlecht . }
}';

$stream_context = stream_context_create( array('http'=>array('timeout' => 2.0)));
$access_rdf_data = fopen("http://localhost:8080/fuseki/db_pedia_rdf_data/query?output=text/turtle&query=" . urlencode($query_string), 'r', false, $stream_context);

if($access_rdf_data == false)
{
 $_GET['is_name'] = 'not_a_name';
 $_GET['has_category'] = 'has_no_category'; 
}

else
{
$turtle_data_from_server = stream_get_contents($access_rdf_data);
$parser = ARC2::getRDFParser();
$base = ''; // Whatever. Seems necessary. I don't know why.
$parser->parse($base,$turtle_data_from_server);
$results_as_array = $parser->getSimpleIndex(0);

/* Count results */

if( key($results_as_array) == "errors" or empty($results_as_array) or count($results_as_array) == 0)
{
 $_GET['is_name'] = 'not_a_name';
 $_GET['has_category'] = 'has_no_category';
}

else if(count($results_as_array) == 1)
{
 $_GET['has_category'] = 'yes_has_category';
 $which_one = 0;
}

else if(count($results_as_array) > 1)
{
 $which_one = rand(0,count($results_as_array)-1);
 $_GET['has_category'] = 'yes_has_category';
}

/* \\ End of count results */

/* Set chosen lemma, take category and check if lemma is a person */

if ($_GET['has_category'] == 'yes_has_category')
{
 $chosen_lemma = $results_as_array[array_keys($results_as_array)[$which_one]];
 $_GET['lemma_label'] = $chosen_lemma['http://www.w3.org/2000/01/rdf-schema#label'][0]['value'];
 $_GET['wikipedia_address'] = str_replace("http://de.dbpedia.org/resource/","http://de.wikipedia.org/wiki/",array_keys($results_as_array)[$which_one]);
 
 /* Pick category */
 $anzahl_kategorien = count($chosen_lemma['http://purl.org/dc/terms/subject']);
 
 if($anzahl_kategorien >= 2) /* Important: Only if there are two or more categories we are able to randomly pick two categories */
 {
  $category_random1 = rand(0,$anzahl_kategorien-1);
  do
   { $category_random2 = rand(0,$anzahl_kategorien-1); } 
  while ($category_random1 == $category_random2);
  $_GET['random_category'] = $chosen_lemma['http://purl.org/dc/terms/subject'][$category_random1]['value'];
  $_GET['random_category2'] = $chosen_lemma['http://purl.org/dc/terms/subject'][$category_random2]['value']; 
 }
 else /* If there is only one category: Set the first one and set "Geschichtsbewusstsein" as the second */
 {
  $_GET['random_category'] = $chosen_lemma['http://purl.org/dc/terms/subject'][0]['value'];
  $_GET['random_category2'] = "Geschichtsbewusstsein";   
 }
 
 /* \\ End of pick category */
 
 if( isset($chosen_lemma['http://de.dbpedia.org/property/sterbedatum'][0]['value']))
 { 
  $_GET['is_name'] = 'yes_is_name';
  $_GET['person_year_of_death'] = $chosen_lemma['http://de.dbpedia.org/property/sterbedatum'][0]['value'];
  $_GET['person_gender'] = $chosen_lemma['http://xmlns.com/foaf/0.1/gender'][0]['value'];
 }
 else
 { $_GET['is_name'] = 'not_a_name'; }
}
}
/* \\ Set chosen lemma, take category and check if lemma is a person */
}

function access_xml_for_title_and_text()
{
 $parsing_results = array();
 $parsing_results["title"] = '';
 $parsing_results["text"] = '';
 
 $xmlDocument = new DOMDocument();
 $xmlDocument->load("generator_data.xml");
 $xml_docment_root = $xmlDocument->documentElement;
 $xpath = new DOMXPath($xmlDocument);

 foreach ($xpath->evaluate("//main_text")->item(0)->childNodes as $context_node) 
 /* In generator_data.xml there are several "choice" elements as childs of "main_text".
  * Each of them represents one "sentence" in the output. So, we go through them all, 
  * Process them and append them to $result_string. Same goes for the title of the seminar.
  * */
 {  $parsing_results["text"] .= parse_xml($context_node,$xpath); }

 foreach ($xpath->evaluate("//text_title")->item(0)->childNodes as $context_node)
 {  $parsing_results["title"] .= parse_xml($context_node,$xpath); }
 
 return $parsing_results;
} 

function parse_xml($context_node,$xpath)
{
 $function_process_string = ''; // set to zero sized string before starting
 
 // Go through the various types of elements within generator_data.xml
 if($context_node->nodeName == 'choice')
   {
    $how_many_options = $xpath->evaluate("count(option[not(@condition) or contains(@condition,'{$_GET['is_name']}') or contains(@condition,'{$_GET['has_category']}')])",$context_node);
    /* count number of available options whithin a choice element. An "option" element is available if it has no condition at all
     * or if it has the appropriate condition. i.e. if the current user input data meets the condition of the current option element:
     * i.e. if condition contains the value of the $_GET variables set based upon the DBpedia data.
     * 
     */
    
    $random_number = rand(1,$how_many_options);
    $option_taken = $xpath->evaluate("option[not(@condition) or contains(@condition,'{$_GET['is_name']}') or contains(@condition,'{$_GET['has_category']}')][$random_number]",$context_node); 
    foreach($option_taken->item(0)->childNodes as $parameter_for_parse)
    { $function_process_string .= parse_xml($parameter_for_parse,$xpath); }
  }

  else if($context_node->nodeName == 'determination')
  {
   $test_field = $context_node->getAttribute('field');
   $test_field_value = $_GET[$test_field];
   $determination_to_take = $xpath->evaluate("if[contains(@equal_to,'{$test_field_value}')]",$context_node); 
   foreach($determination_to_take->item(0)->childNodes as $parameter_for_parse)
    { $function_process_string .= parse_xml($parameter_for_parse,$xpath); }
  }
  
  else if($context_node->nodeName == 'replace')
  { $function_process_string .= replace_dangerous_chars($_GET[$context_node->getAttribute('with')]); }

  else if($context_node->nodeName == 'wiki_link')
  { 
   $function_process_string .= '<a href="' . $_GET['wikipedia_address'] . '">'; 
   
   foreach($context_node->childNodes as $parameter_for_parse)
    { $function_process_string .= parse_xml($parameter_for_parse,$xpath); }
   
   $function_process_string .= '</a>'; 
  }
  
  else if($context_node->nodeType == 3) /* NoteType: Text node */
  { $function_process_string .=  $context_node->nodeValue; }
  
  return $function_process_string;
}

function prepare_output($parsing_results)
{
 $output_string = '';
 $output_string .= '<div class="output">';
 $output_string .=  '<h2>' . $parsing_results["title"] . '</h2>';
 $output_string .=  '<p>' . $parsing_results["text"] . "</p>";
 $output_string .=  '</div>';
 return $output_string;
}
 
function output($output_string)
{
 $output_web_page = file_get_contents('vorlesungskommentargenerator.html');
 $output_web_page = str_replace('<!--OUTPUTHERE-->',$output_string,$output_web_page);
 $output_web_page = fillInFormValues($output_web_page,$_GET);

 header('Content-Type: text/html; charset=utf-8');

 echo $output_web_page;
 exit(0);
} 

function replace_dangerous_chars($process_string)
{
$repl_string = '';
 $process_string = str_replace('<',$repl_string,$process_string);
 $process_string = str_replace('>',$repl_string,$process_string);
 $process_string = str_replace('"',$repl_string,$process_string);
 $process_string = str_replace('{',$repl_string,$process_string);
 $process_string = str_replace('}',$repl_string,$process_string);
 $process_string = str_replace('\'',$repl_string,$process_string);
 $process_string = str_replace(';',$repl_string,$process_string);
 $process_string = str_replace('?',$repl_string,$process_string);
 $process_string = str_replace('*',$repl_string,$process_string);
 $process_string = str_replace('#',$repl_string,$process_string);
 $process_string = str_replace('+',$repl_string,$process_string);
 $process_string = str_replace('=',$repl_string,$process_string);
 $process_string = str_replace('[',$repl_string,$process_string);
 $process_string = str_replace(']',$repl_string,$process_string);
 $process_string = str_replace('/',$repl_string,$process_string);
 $process_string = str_replace('\\',$repl_string,$process_string);
 return $process_string;
}

?>
