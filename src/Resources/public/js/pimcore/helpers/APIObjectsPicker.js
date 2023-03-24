pimcore.registerNS("pimcore.plugin.storeExporterDataObject.helpers.workspace.apiObjects");
pimcore.plugin.storeExporterDataObject.helpers.workspace.apiObjects = Class.create(pimcore.plugin.datahub.workspace.abstract, {

    type: "object",
    initialize: function (parent) {
        this.parent = parent;
        this.workspaces = this.parent.data.APIAccess;
    },

    //doing this to override the onNodeOver function
    updateRows: function () {

        var rows = Ext.get(this.grid.getEl().dom).query(".x-grid-row");

        for (var i = 0; i < rows.length; i++) {

            var dd = new Ext.dd.DropZone(rows[i], {
                ddGroup: "element",

                getTargetFromEvent: function(e) {
                    return this.getEl();
                },

                onNodeOver : function(target, dd, e, data) {
                    var APIAccessTypes = ["TorqStoreExporterShopifyCredentials"];
                    if (data.records.length == 1 && data.records[0].data.elementType == this.type && data.records[0].data.className && APIAccessTypes.includes(data.records[0].data.className)) {
                        return Ext.dd.DropZone.prototype.dropAllowed;
                    }
                }.bind(this),

                onNodeDrop : function(myRowIndex, target, dd, e, data) {
                    if (pimcore.helpers.dragAndDropValidateSingleItem(data)) {
                        try {
                            var record = data.records[0];
                            var data = record.data;

                            // check for duplicate records
                            var index = this.grid.getStore().findExact("cpath", data.path);
                            if (index >= 0) {
                                return false;
                            }

                            if (data.elementType != this.type) {
                                return false;
                            }

                            var rec = this.grid.getStore().getAt(myRowIndex);
                            rec.set("cpath", data.path);

                            this.updateRows();

                            return true;
                        } catch (e) {
                            console.log(e);
                        }
                    }
                }.bind(this, i)
            });
        }

    },
});