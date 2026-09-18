<?php

use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminUrls;

$elements = AdminElements::Instance();
$elements->Header("Duplicates -- delete result");

$backUrl = AdminUrls::Instance()->GetFeatureUrl("AdminDuplicates");

?>
<div class="container">
	<div class="row">
		<div class="col-12">
			<div class="card">
				<div class="card-header">Deleted <?=number_format($deletedFiles)?> file(s) across <?=number_format($deletedGroups)?> duplicate group(s)</div>
				<div class="card-body">
					<p><?=count($remainingGroups)?> duplicate group(s) remain.</p>
					<a class="btn btn-primary" href="<?=$backUrl?>">Back to Duplicates</a>
				</div>
			</div>
		</div>
	</div>
</div>
