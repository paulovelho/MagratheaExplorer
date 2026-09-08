<?php

use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminManager;
use Magrathea2\Admin\AdminUrls;

$elements = AdminElements::Instance();
$elements->Header("Keys");
$feature = AdminManager::Instance()->GetActiveFeature();

?>
<div class="container">
	<div class="row">
		<div class="col-12">
			<a class="btn btn-success" href="<?=AdminUrls::Instance()->GetFeatureUrl($feature->featureId, "Form")?>">New Key</a>
		</div>
	</div>
	<div class="row mt-3">
		<div class="col-12">
			<? $feature->List(); ?>
		</div>
	</div>
</div>
