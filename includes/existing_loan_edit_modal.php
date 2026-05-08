<?php
/** @var array $office_school_options */
/** @var array $deped_position_options */
?>
<form method="post" enctype="multipart/form-data" id="elModalEditForm" class="existing-loan-modal-form" data-require-dc="yes" autocomplete="off">
    <input type="hidden" name="existing_loan_action" value="update">
    <input type="hidden" name="loan_id" id="mel_loan_id" value="">

    <div class="el-edit-modal-body">
        <nav class="el-edit-modal-toc" aria-label="Jump to section">
            <span class="el-edit-modal-toc-label"><i class="fas fa-bars"></i> Go to</span>
            <a href="#el-edit-sec-borrower">Borrower</a>
            <a href="#el-edit-sec-loan">Loan &amp; employment</a>
            <a href="#el-edit-sec-comaker">Co-maker</a>
        </nav>
        <section class="el-section-card el-edit-sec" id="el-edit-sec-borrower">
            <h3 class="el-section-head"><i class="fas fa-user"></i> Borrower</h3>
            <div class="form-group el-span-2">
                <label for="mel_borrower_display">Account</label>
                <input type="text" id="mel_borrower_display" readonly class="el-readonly-soft" value="">
                <small>Name and email are tied to the borrower account. To change them, use Manage Users.</small>
            </div>
        </section>

        <section class="el-section-card el-edit-sec" id="el-edit-sec-loan">
            <h3 class="el-section-head"><i class="fas fa-file-invoice-dollar"></i> Loan &amp; borrower (on file)</h3>
            <div class="el-form-grid">
                <div class="form-group">
                    <label for="mel_loan_amount">Loan amount (₱) *</label>
                    <input type="number" name="loan_amount" id="mel_loan_amount" min="1000" max="100000" step="1" inputmode="decimal" required value="">
                </div>
                <div class="form-group">
                    <label for="mel_loan_term">Term (months) *</label>
                    <select name="loan_term" id="mel_loan_term" required>
                        <?php foreach ([6, 12, 18, 24, 30, 36, 42, 48, 54, 60] as $mo): ?>
                            <option value="<?php echo (int) $mo; ?>"><?php echo (int) $mo; ?> months</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="mel_net_pay">Net pay (₱) *</label>
                    <input type="number" name="net_pay" id="mel_net_pay" step="0.01" min="0" required value="">
                </div>
                <div class="form-group">
                    <label for="mel_already_paid_amount">Already paid / nahulog na (₱)</label>
                    <input type="number" name="already_paid_amount" id="mel_already_paid_amount" step="0.01" min="0" value="">
                    <small>Cannot be less than deductions already recorded.</small>
                </div>
                <div class="form-group">
                    <label for="mel_application_date">Application / start date *</label>
                    <input type="date" name="application_date" id="mel_application_date" required value="">
                </div>
                <div class="el-release-status-school-row" id="melReleaseStatusSchoolRow">
                    <div class="form-group">
                        <label for="mel_released_at">Released at</label>
                        <input type="datetime-local" name="released_at" id="mel_released_at" value="">
                        <small>Optional release timestamp.</small>
                    </div>
                    <div class="form-group">
                        <label for="mel_loan_status">Record status *</label>
                        <select name="loan_status" id="mel_loan_status" required>
                            <option value="approved">Approved (paying / active)</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="mel_school_assignment">Office / school *</label>
                        <select name="school_assignment" id="mel_school_assignment" required>
                            <option value="">— Select —</option>
                            <?php foreach ($office_school_options as $group => $items): ?>
                                <optgroup label="<?php echo htmlspecialchars($group); ?>">
                                    <?php foreach ($items as $opt): ?>
                                        <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="mel_position">Position *</label>
                        <select name="position" id="mel_position" required>
                            <option value="">— Select —</option>
                            <?php foreach ($deped_position_options as $group => $items): ?>
                                <optgroup label="<?php echo htmlspecialchars($group); ?>">
                                    <?php foreach ($items as $opt): ?>
                                        <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="mel_salary_grade">Salary grade *</label>
                        <select name="salary_grade" id="mel_salary_grade" required>
                            <option value="">—</option>
                            <?php foreach (range(1, 33) as $grade): ?>
                                <option value="<?php echo $grade; ?>">Grade <?php echo $grade; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="mel_employment_status">Employment status *</label>
                    <select name="employment_status" id="mel_employment_status" required>
                        <option value="">— Select —</option>
                        <?php foreach (['Permanent', 'Contractual', 'Substitute', 'Provisional', 'Probationary'] as $opt): ?>
                            <option value="<?php echo $opt; ?>"><?php echo $opt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="mel_borrower_date_of_birth">Borrower DOB</label>
                    <input type="date" name="borrower_date_of_birth" id="mel_borrower_date_of_birth" value="">
                </div>
                <div class="form-group">
                    <label for="mel_borrower_years_of_service">Borrower years of service</label>
                    <input type="number" name="borrower_years_of_service" id="mel_borrower_years_of_service" min="0" step="1" value="0">
                </div>
                <div class="form-group el-tail-span-2">
                    <label for="mel_loan_purpose">Loan purpose *</label>
                    <textarea name="loan_purpose" id="mel_loan_purpose" required></textarea>
                </div>
                <p class="el-edit-scroll-hint el-tail-span-2"><i class="fas fa-info-circle"></i> <strong>Co-maker</strong> (name, school, IDs context) is in the section below — use <strong>Go to</strong> at the top or scroll down.</p>
            </div>
        </section>

        <section class="el-section-card el-edit-sec" id="el-edit-sec-comaker">
            <h3 class="el-section-head"><i class="fas fa-user-friends"></i> Co-maker</h3>
            <div class="el-form-grid">
                <div class="form-group">
                    <label for="mel_co_maker_last_name">Co-maker last name *</label>
                    <input type="text" name="co_maker_last_name" id="mel_co_maker_last_name" required value="">
                </div>
                <div class="form-group">
                    <label for="mel_co_maker_first_name">Co-maker first name *</label>
                    <input type="text" name="co_maker_first_name" id="mel_co_maker_first_name" required value="">
                </div>
                <div class="form-group">
                    <label for="mel_co_maker_middle_name">Co-maker middle name *</label>
                    <input type="text" name="co_maker_middle_name" id="mel_co_maker_middle_name" required value="">
                </div>
                <div class="form-group">
                    <label for="mel_co_maker_email">Co-maker email *</label>
                    <input type="email" name="co_maker_email" id="mel_co_maker_email" required value="">
                </div>
                <div class="form-group">
                    <label for="mel_co_maker_position">Co-maker position *</label>
                    <select name="co_maker_position" id="mel_co_maker_position" required>
                        <option value="">— Select —</option>
                        <?php foreach ($deped_position_options as $group => $items): ?>
                            <optgroup label="<?php echo htmlspecialchars($group); ?>">
                                <?php foreach ($items as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="mel_co_maker_school_assignment">Co-maker school *</label>
                    <select name="co_maker_school_assignment" id="mel_co_maker_school_assignment" required>
                        <option value="">— Select —</option>
                        <?php foreach ($office_school_options as $group => $items): ?>
                            <optgroup label="<?php echo htmlspecialchars($group); ?>">
                                <?php foreach ($items as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="mel_co_maker_net_pay">Co-maker net pay *</label>
                    <input type="number" name="co_maker_net_pay" id="mel_co_maker_net_pay" step="0.01" min="0" required value="">
                </div>
                <div class="form-group">
                    <label for="mel_co_maker_employment_status">Co-maker employment *</label>
                    <select name="co_maker_employment_status" id="mel_co_maker_employment_status" required>
                        <option value="">— Select —</option>
                        <?php foreach (['Permanent', 'Contractual', 'Substitute', 'Provisional', 'Probationary'] as $opt): ?>
                            <option value="<?php echo $opt; ?>"><?php echo $opt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="mel_co_maker_date_of_birth">Co-maker DOB</label>
                    <input type="date" name="co_maker_date_of_birth" id="mel_co_maker_date_of_birth" value="">
                </div>
                <div class="form-group">
                    <label for="mel_co_maker_years_of_service">Co-maker years of service</label>
                    <input type="number" name="co_maker_years_of_service" id="mel_co_maker_years_of_service" min="0" step="1" value="0">
                </div>
            </div>
        </section>
    </div>

    <div class="el-edit-modal-foot">
        <button type="button" class="btn-ghost" id="elEditModalCancel"><i class="fas fa-times"></i> Cancel</button>
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save changes</button>
    </div>
</form>
