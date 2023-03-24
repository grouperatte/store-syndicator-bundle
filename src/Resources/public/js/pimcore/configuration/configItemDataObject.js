pimcore.registerNS('pimcore.plugin.storeExporterDataObject.configuration.configItemDataObject');
pimcore.plugin.storeExporterDataObject.configuration.configItemDataObject = Class.create(pimcore.plugin.datahub.configuration.graphql.configItem, {

    config: {
        attributeStore: null,
        objectTree: null,
        configName: null,
        classStore: null,
    },

    urlSave: Routing.generate('pimcore_storesyndicator_configdataobject_save'),
    
    getPanels: function () {
        return [
            this.buildGeneralTab(),
            this.buildProductsTab(),
            this.buildAttributeMappingTab(),
            this.buildAccessTab(),
            this.buildExecutionTab()
        ];
    },

    initialize: function (data, parent) {

        //TODO make that more generic in datahub
        this.parent = parent;
        this.configName = data.name;
        this.data = data.configuration;
        this.userPermissions = data.userPermissions;
        this.modificationDate = data.modificationDate;

        /**
         * Set writeable to true, if it is undefined.
         * This is done because of backwards compatability to version 6.9-
         * Otherwise the save button would be disabled.
         */
        if (typeof this.data.general.writeable === 'undefined') {
            this.data.general.writeable = true;
        }

        this.tab = Ext.create('Ext.TabPanel', {
            title: this.data.general.name,
            closable: true,
            deferredRender: true,
            forceLayout: true,
            iconCls: "plugin_pimcore_datahub_icon_" + this.data.general.type,
            id: "plugin_pimcore_datahub_configpanel_panel_" + data.name,
            buttons: {
                componentCls: 'plugin_pimcore_datahub_statusbar',
                itemId: 'footer'
            }
        });

        this.tab.columnHeaderStore = Ext.create('Ext.data.Store', {
            fields: ['id', 'dataIndex', 'label'],
            data: data.columnHeaders,
            autoDestroy: false
        });

        this.tab.add(this.getPanels());
        this.tab.setActiveTab(0);

        // this.tab.on("activate", this.tabactivated.bind(this));
        // this.tab.on("destroy", this.tabdestroy.bind(this));
        // this.tab.on('render', this.isValid.bind(this, false));
        //this.setupChangeDetector();

        this.parent.configPanel.editPanel.add(this.tab);
        this.parent.configPanel.editPanel.setActiveTab(this.tab);
        this.parent.configPanel.editPanel.updateLayout();

        var footer = this.tab.getDockedComponent('footer');

        footer.removeAll();

        footer.add('->');

        let saveButtonConfig = {
            text: t("save"),
            iconCls: "pimcore_icon_apply",
            disabled: !this.data.general.writeable || !this.userPermissions.update,
            handler: this.save.bind(this)
        };
        if(!this.data.general.writeable) {
            saveButtonConfig.tooltip = t("config_not_writeable");
        }
        footer.add(saveButtonConfig);
    },
    buildGeneralTab: function() {
        this.generalForm = Ext.create('Ext.form.FormPanel', {
            bodyStyle: "padding:10px;",
            autoScroll: true,
            defaults: {
                labelWidth: 200,
                width: 600
            },
            border: false,
            title: t('plugin_pimcore_datahub_configpanel_item_general'),
            items: [
                {
                    xtype: "checkbox",
                    fieldLabel: t("active"),
                    name: "active",
                    inputValue: true,
                    value: this.data.general && this.data.general.hasOwnProperty("active") ? this.data.general.active : false
                },
                {
                    xtype: "textfield",
                    fieldLabel: t("type"),
                    name: "type",
                    value: t("plugin_pimcore_datahub_type_" + this.data.general.type),
                    readOnly: true
                },
                {
                    xtype: "textfield",
                    fieldLabel: t("name"),
                    name: "name",
                    value: this.data.general.name,
                    readOnly: true
                },
                {
                    name: "description",
                    fieldLabel: t("description"),
                    xtype: "textarea",
                    height: 100,
                    value: this.data.general.description
                },
            ]
        });
        return this.generalForm;
    },
    buildProductsTab: function() {
        if(!this.objectWorkspace){
            this.objectWorkspace = new pimcore.plugin.storeExporterDataObject.helpers.workspace.object(this);
        }
        if(!this.classStore){
            this.classStore = Ext.create('Ext.data.Store', {
                fields: ['name'],
                proxy: {
                    method: 'GET',
                    url: Routing.generate('pimcore_storesyndicator_product_choice_get_classes'),
                    noCache: false,
                    type: 'ajax',
                    root: 'result',
                    totalProperty: 'total',
                },
                autoLoad: true
            });
        }
        this.productsTab = Ext.create('Ext.form.FormPanel', {
            bodyStyle: "padding:10px;",
            autoScroll: true,
            defaults: {
                labelWidth: 200,
                width: 600
            },
            border: false,
            title: t('plugin_pimcore_datahub_configpanel_item_products'),
            items: [
                {
                    xtype: "combobox",
                    fieldLabel: t("BaseClass"),
                    name: "class",
                    value: this.data.products.class ?? '',
                    store: this.classStore, 
                    valueField: 'name',
                    displayField: 'name',
                },
                this.objectWorkspace.getPanel()
            ]
        });
        //this.objectTree = new pimcore.plugin.storeExporterDataObject.helpers.objectTree(this.productsTab, this.data.general.name)
        return this.productsTab;
    },
    buildAttributeMappingTab: function() {
        if(!this.attributeStore){
            this.attributeStore = Ext.create('Ext.data.Store', {
                fields: ['local field', 'remote field'],
                data: this.data.attributeMap,
                pageSize: 0
            });
        }
        if(!this.localAttributesStore){
            this.localAttributesStore = Ext.create('Ext.data.Store', {
                fields: ['name'],
                proxy: {
                    method: 'GET',
                    url: Routing.generate('pimcore_storesyndicator_attributes_get_local'),
                    noCache: false,
                    type: 'ajax',
                    root: 'result',
                    totalProperty: 'total',
                    extraParams: {'name': this.data.general.name}
                },
                autoLoad: true,
                pageSize: 0
            });
        }
        if(!this.remoteAttributeTypeStore){
            this.remoteAttributeTypeStore = Ext.create('Ext.data.Store', {
                fields: ['name'],
                proxy: {
                    method: 'GET',
                    url: Routing.generate('pimcore_storesyndicator_attributes_get_remote_types'),
                    noCache: false,
                    type: 'ajax',
                    root: 'result',
                    totalProperty: 'total'
                },
                autoLoad: true,
                pageSize: 0
            });
        }
        if(!this.remoteAttributesStore){
            this.remoteAttributesStore = Ext.create('Ext.data.Store', {
                fields: ['name', 'type'],
                proxy: {
                    method: 'GET',
                    url: Routing.generate('pimcore_storesyndicator_attributes_get_remote'),
                    noCache: false,
                    type: 'ajax',
                    root: 'result',
                    totalProperty: 'total',
                    extraParams: {'name': this.data.general.name}//change to something about what kind of export this is
                },
                autoLoad: true,
                pageSize: 0
            });
        }
        
        grid = Ext.create('Ext.grid.Panel', {
            title: t('plugin_pimcore_datahub_configpanel_item_attribute_mapping'),
            plugins: [Ext.create('Ext.grid.plugin.CellEditing', {
                clicksToEdit: 1,
                delay: 10
            })],
            viewConfig: {
                plugins: {
                    ptype: 'gridviewdragdrop',
                    containerScroll: true,
                    dragGroup: t('plugin_pimcore_datahub_configpanel_item_attribute_mapping'),
                    dropGroup: t('plugin_pimcore_datahub_configpanel_item_attribute_mapping')
                },
            },
            tbar: [{
                text: 'Add Mapping',
                handler: function () {
                    let rec = {'local field': "", 'remote field': ""};
                    this.attributeStore.insert(0, rec);
                }.bind(this)
            }],
            store: this.attributeStore,
            width: "auto",
            columns: [
                {
                    text: 'local field',
                    dataIndex: 'local field',
                    width: 200,
                    editor: {
                        xtype: 'combobox',
                        queryMode: 'local',
                        valueField: 'name',
                        displayField: 'name',
                        store: this.localAttributesStore,
                        listeners: {
                            change: function (thisCmb, newValue, oldValue) {
        
                            },
                            beforerender: function (thisCmb, eOpts) {
        
                            }
                        }
                    }
                },
                {
                    text: 'field type',
                    dataIndex: 'field type',
                    width: 200,
                    tooltip: t('plugin_pimcore_datahub_configpanel_item_remote_type_header_tip'),
                    editor: {
                        xtype: 'combobox',
                        queryMode: 'local',
                        valueField: 'name',
                        displayField: 'name',
                        store: this.remoteAttributeTypeStore,
                        listeners: {
                            beforerender: function (thisCmb, eOpts) {
        
                            },
                        }
                    }                    
                },
                {
                    text: 'remote field',
                    dataIndex: 'remote field',
                    width: 200,
                    tooltip: t('plugin_pimcore_datahub_configpanel_item_remote_header_tip'),
                    editor: {
                        xtype: 'combobox',
                        queryMode: 'local',
                        valueField: 'name',
                        displayField: 'name',
                        store: this.remoteAttributesStore,
                        listeners: {
                            expand: function(combo){
                                combo.store.clearFilter();
                                var type = combo.up('grid').editingPlugin.activeRecord.get('field type');
                                combo.store.filterBy(function(record){
                                    return record.get('type') == type;
                                });
                            }
                        }
                    }
                },
                {
                    xtype:'actioncolumn',
                    width:30,
                    items: [{
                        iconCls: 'plugin_pimcore_datahub_icon_storeExporterDataObject_delete_row',
                        tooltip: 'Delete',
                        handler: function(grid, rowIndex, colIndex) {
                            grid.getStore().removeAt(rowIndex);
                        },
                        scope: this
                    }]
                }
            ],
        });
        this.attributeMappingForm = Ext.create('Ext.form.FormPanel', {
            bodyStyle: "padding:10px;",
            autoScroll: true,
            defaults: {
                labelWidth: 200,
                width: 600
            },
            border: false,
            title: t('plugin_pimcore_datahub_configpanel_item_attribute_mapping'),
            items: [
                grid
            ],
            buttons: [{
                text: t("plugin_pimcore_datahub_configpanel_item_reload_fields"),
                handler: function(){
                    this.localAttributesStore.load();
                    this.remoteAttributesStore.load();
                    this.remoteAttributeTypeStore.load();
                }.bind(this)
            }]
        });
        return this.attributeMappingForm;
    },
    buildAccessTab: function(){
        if(!this.APIAccessPicker){
            this.APIAccessPicker = new pimcore.plugin.storeExporterDataObject.helpers.workspace.apiObjects(this);
        }
        this.accessForm = Ext.create('Ext.form.FormPanel', {
            bodyStyle: "padding:10px;",
            autoScroll: true,
            defaults: {
                labelWidth: 200,
                width: 600
            },
            border: false,
            title: t('plugin_pimcore_datahub_configpanel_item_access'),
            items: [
                this.APIAccessPicker.getPanel()
            ]
        });
        return this.accessForm;
    },
    buildExecutionTab: function() {
        let manualExecute = Ext.create('Ext.Button', {
            text: t('plugin_pimcore_datahub_configpanel_item_manual_execute'),
            handler: function() {
                let url = Routing.generate('pimcore_storesyndicator_execution_execute');
                Ext.Ajax.request({
                    url: url,
                    method: 'POST',
                    params: {
                        name: this.data.general.name
                    }
                })
            }.bind(this)
        });
        if(!this.logStore){
            this.logStore = Ext.create('Ext.data.Store', {
                fields: ['comment', 'log'],
                data: this.data.ExportLogs ?? [],
                pageSize: 0
            });
        }
        let logPanel = Ext.create('Ext.grid.Panel', {
            store: this.logStore,
            viewConfig: {
                forceFit: true,
                enableTextSelection: true 
            },
            columns: [
                {
                    text: "Log Comment",
                    dataIndex: "comment",
                    columnWidth: "5%",
                },
                {
                    text: "Log",
                    dataIndex: "log",
                    renderer: function (value, metaData) {
                        return '<div style="white-space:normal">' + value + '</div>';
                    },
                }
            ]
        });
        this.executionForm = Ext.create('Ext.form.FormPanel', {
            bodyStyle: "padding:10px;",
            autoScroll: true,
            defaults: {
                labelWidth: 200,
                forceFit: true
            },
            border: false,
            title: t('plugin_pimcore_datahub_configpanel_item_execution'),
            //add some config for cron
            items: [
                manualExecute,
                logPanel
            ]
        });
        return this.executionForm;
    },
    save: function(){
        var saveData = this.getSaveData();

        Ext.Ajax.request({
            url: this.urlSave,
            params: {
                data: JSON.stringify(saveData),
                modificationDate: this.modificationDate
            },
            method: "post",
            success: function (response) {
                var rdata = Ext.decode(response.responseText);
                if (rdata && rdata.success) {
                    this.modificationDate = rdata.modificationDate;
                    this.saveOnComplete();
                }
                else if(rdata && rdata.permissionError) {
                        pimcore.helpers.showNotification(t("error"), t("plugin_pimcore_datahub_configpanel_item_saveerror_permissions"), "error");
                        this.tab.setActiveTab(this.tab.items.length-1);
                } else {
                    pimcore.helpers.showNotification(t("error"), t("plugin_pimcore_datahub_configpanel_item_saveerror"), "error", t(rdata.message));
                }
            }.bind(this)
        });
    },
    getSaveData: function() {
        let saveData = {};
        let store = this.attributeStore;

        var gridData = [];
        store.each(function(r) {
            gridData.push(r.getData());
        });
        saveData['attributeMap'] = gridData;

        let productsData = {};
        // old product picker
        // store = this.objectTree.getTree().getStore();
        // gridData = [];
        // store.each(function(r) {
        //     data = r.getData()
        //     if(data["checked"]){
        //         gridData.push(data["id"]);
        //     }
        // });
        let pickedDataobjects = this.objectWorkspace.getValues();
        productsData['products'] = pickedDataobjects;
        productsData['class'] = this.productsTab.getValues().class

        saveData['general'] = this.generalForm.getValues();

        let chosenAPIAccessObject = this.APIAccessPicker.getValues();
        saveData['APIAccess'] = chosenAPIAccessObject;
        
        saveData['products'] = productsData;
        return saveData;
    },
    saveOnComplete: function () {
        this.parent.configPanel.tree.getStore().load({
            node: this.parent.configPanel.tree.getRootNode()
        });

        pimcore.helpers.showNotification(t("success"), t("plugin_pimcore_datahub_configpanel_item_save_success"), "success");

        this.resetChanges();
    },
});
