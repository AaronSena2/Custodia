<?php
/**
 * Shared Bootstrap modals for check-out / check-in / transfer, used by both
 * matter.php's Physical Files tab and scan.php (the Scan Station). The
 * including page must have $locations already loaded, and a
 * <script> including assets/js/app.js already on the page (layout_header.php
 * provides that). After a successful action the page simply reloads —
 * simplicity over a fancier in-place refresh, consistent with the rest of
 * this rebuild's "plain PHP, no framework" approach.
 */
?>
<div class="modal fade" id="checkoutModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Issue <span id="checkoutBarcode" class="barcode-display"></span></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="checkoutForm" data-action-url="actions/checkout_file.php">
    <input type="hidden" name="fileId" id="checkoutFileId">
    <div class="modal-body">
      <div class="form-error alert alert-danger d-none"></div>
      <div class="mb-3"><label class="form-label">Reason</label><textarea class="form-control" name="reason" required></textarea></div>
      <div class="mb-3"><label class="form-label">Due Back</label><input type="date" class="form-control" name="dueBackAt" required value="<?= e((new DateTimeImmutable('+7 days'))->format('Y-m-d')) ?>"></div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Issue</button></div>
  </form>
</div></div></div>

<div class="modal fade" id="checkinModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="checkinTitle">Return</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="checkinForm" data-action-url="actions/checkin_file.php">
    <input type="hidden" name="fileId" id="checkinFileId">
    <input type="hidden" name="override" id="checkinOverride" value="0">
    <div class="modal-body">
      <div class="form-error alert alert-danger d-none"></div>
      <div class="mb-3"><label class="form-label">Return Location</label>
        <select class="form-select" name="locationId" required>
          <?php foreach ($locations as $loc): ?>
            <option value="<?= e($loc['id']) ?>"><?= e($loc['building']) ?> / <?= e($loc['room']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label">Reason / Notes</label><textarea class="form-control" name="reason" placeholder="Routine return"></textarea></div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn btn-success w-100">Return</button></div>
  </form>
</div></div></div>

<div class="modal fade" id="transferModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Request Transfer — <span id="transferBarcode" class="barcode-display"></span></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="transferForm" data-action-url="actions/request_transfer.php">
    <input type="hidden" name="fileId" id="transferFileId">
    <div class="modal-body">
      <div class="form-error alert alert-danger d-none"></div>
      <p class="text-muted small">This sends a request to the file's current custodian — it only transfers custody to you once they approve it.</p>
      <div class="mb-3"><label class="form-label">Reason</label><textarea class="form-control" name="reason" required></textarea></div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn btn-secondary w-100">Request Transfer</button></div>
  </form>
</div></div></div>

<script>
function openCheckoutModal(fileId, barcode) {
  document.getElementById('checkoutFileId').value = fileId;
  document.getElementById('checkoutBarcode').textContent = barcode;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('checkoutModal')).show();
}
function openCheckinModal(fileId, barcode, isOverride) {
  document.getElementById('checkinFileId').value = fileId;
  document.getElementById('checkinOverride').value = isOverride ? '1' : '0';
  document.getElementById('checkinTitle').textContent = (isOverride ? 'Override Return ' : 'Return ') + barcode;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('checkinModal')).show();
}
function openTransferModal(fileId, barcode) {
  document.getElementById('transferFileId').value = fileId;
  document.getElementById('transferBarcode').textContent = barcode;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('transferModal')).show();
}
// Default: flash the message then reload the page, as this always did — the
// right behavior on a page like matter.php's Physical Files tab, where you
// want to see the updated status in place. A page can override this (define
// window.custodiaCustodyActionComplete itself, in a <script> block BEFORE
// this file is require()'d) for a different post-action flow — see
// scan.php, which replaces the reload with an in-place reset back to a
// ready-to-scan state, since reloading there would leave the just-actioned
// file on screen instead of clearing the way for the next scan.
window.custodiaCustodyActionComplete = window.custodiaCustodyActionComplete || function (message) {
  custodiaFlash(message);
  window.location.reload();
};
custodiaWireActionForm(document.getElementById('checkoutForm'), () => custodiaCustodyActionComplete('File issued.'));
custodiaWireActionForm(document.getElementById('checkinForm'), () => custodiaCustodyActionComplete('File returned.'));
custodiaWireActionForm(document.getElementById('transferForm'), () => custodiaCustodyActionComplete('Transfer requested.'));
</script>
