<?php
require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");

header('Content-Type: application/json');

$compose_root = locate_compose_root('compose.manager');
$projects = @array_diff(@scandir($compose_root), array('.', '..'));
$out = array();
if (!is_array($projects)) {
    echo json_encode(array());
    exit;
}

foreach ($projects as $project) {
    if ((!is_file("$compose_root/$project/docker-compose.yml") ) && (!is_file("$compose_root/$project/indirect"))) {
        continue;
    }
    $projectName = $project;
    if (is_file("$compose_root/$project/name")) {
        $projectName = trim(file_get_contents("$compose_root/$project/name"));
    }
    $basePath = is_file("$compose_root/$project/indirect") ? trim(file_get_contents("$compose_root/$project/indirect")) : "$compose_root/$project";
    $id = str_replace('.', '-', $project);
    $id = str_replace(' ', '', $id);

    $out[] = array(
        'id' => $id,
        'project' => $project,
        'name' => $projectName,
        'path' => $basePath
    );
}

echo json_encode($out);

?>
