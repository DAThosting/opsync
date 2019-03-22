<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.min.js"></script>

{if $apierror eq '1'}
    <a href="/admin/configaddonmods.php">LOGIN IS INVALID</a>
{else}

    <style>
        SECTION SELECT, INPUT[type="text"] {
            width: 160px;
            box-sizing: border-box;
        }
        SECTION {
            padding: 8px;
            overflow: auto;
        }
        SECTION > DIV {
            float: left;
            padding: 4px;
        }
        SECTION > DIV + DIV {
            width: 40px;
        }
        SECTION > .buttons {
            margin-top: 55px !important;
        }
        SECTION > DIV > p {
            width: 100px !important;
        }
        SECTION > select {
            height: 250px;
        }
        SECTION select[multiple], select[size] {
            height: 500px !important;
        }
    </style>

    <section class="container">
        <div>
            <p>Remove Openprovider<br></p>
            <form method="post" action="#" id="Form">
                <textarea name="remove"></textarea>
        </div>
        <div>
            <p>Autoregister Openprovider</p>
            <textarea name="import">{foreach from=$extensionlistright item=domainright}{$domainright}{/foreach}</textarea>
            <input type="submit" name="submit" value=Process>
            </form>
        </div>
    </section>

{/if}