<?php
$is_edit = !empty($link);
?>
<form method="post">
    <input type="hidden" name="<?= $is_edit?'edit_link':'add_link' ?>" value="1">
    <?php if($is_edit): ?>
        <input type="hidden" name="id" value="<?= $link['id'] ?>">
    <?php endif; ?>

    <div class="mb-3">
        <label class="form-label">Name</label>
        <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($link['name'] ?? '') ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label">IP Address</label>
        <input type="text" class="form-control" name="ip" value="<?= htmlspecialchars($link['ip'] ?? '') ?>" required>
    </div>
    <div class="mb-3">
        <label class="form-label">From Device</label>
        <select class="form-select" name="from_device_id" required>
            <?php foreach($devices as $dev): ?>
                <option value="<?= $dev['id'] ?>" <?= (($link['from_device_id']??0)==$dev['id']?'selected':'') ?>>
                    <?= htmlspecialchars($dev['name']) ?> (<?= $dev['ip'] ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mb-3">
        <label class="form-label">To Device</label>
        <select class="form-select" name="to_device_id" required>
            <?php foreach($devices as $dev): ?>
                <option value="<?= $dev['id'] ?>" <?= (($link['to_device_id']??0)==$dev['id']?'selected':'') ?>>
                    <?= htmlspecialchars($dev['name']) ?> (<?= $dev['ip'] ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <button type="submit" class="btn btn-primary w-100"><?= $is_edit?'Update Link':'Add Link' ?></button>
</form>
